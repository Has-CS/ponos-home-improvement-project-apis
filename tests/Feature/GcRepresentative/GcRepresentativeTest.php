<?php

namespace Tests\Feature\GcRepresentative;

use App\Models\ChangeOrder;
use App\Models\GcRepresentative;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ChangeOrder\ChangeOrderTestCase;

/**
 * GC representatives: CRUD nested under a General Contractor, and their use as the
 * signer on an emergency change order's work authorization.
 *
 * The guarantee that matters most: a signature captured for a representative holds
 * its own COPY of their details, so editing or retiring the representative later
 * never changes what was signed.
 */
class GcRepresentativeTest extends ChangeOrderTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    private function repBase(?ProjectGeneralContractor $gc = null, ?Project $project = null): string
    {
        $gc ??= $this->gc;
        $project ??= $this->project;

        return "/api/v1/projects/{$project->id}/general-contractors/{$gc->id}/representatives";
    }

    private function makeRep(?ProjectGeneralContractor $gc = null, array $overrides = []): GcRepresentative
    {
        return ($gc ?? $this->gc)->representatives()->create([
            'name' => 'Dana Whitfield',
            'title' => 'Site Superintendent',
            'phone' => '(630) 555-0101',
            'email' => 'dana.w@kellermangc.test',
            ...$overrides,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function capture(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->foreman, 'api')->postJson($this->base().'/emergency', [
            'title' => 'Unforeseen conduit conflict at grid C4',
            'scope' => 'Reframe the door opening and make good.',
            'location' => 'Level 3, Unit 312',
            'signature_image' => self::PNG,
            ...$payload,
        ]);
    }

    /* ---------------- CRUD ---------------- */

    public function test_a_pm_can_create_view_update_and_delete_a_representative(): void
    {
        $id = $this->actingAs($this->pm, 'api')->postJson($this->repBase(), [
            'name' => 'Marco Ruiz',
            'title' => 'Project Engineer',
            'phone' => '(630) 555-0144',
            'email' => 'marco@kellermangc.test',
        ])->assertStatus(201)
            ->assertJsonPath('data.company', 'Kellerman Construction Group')
            ->assertJsonPath('data.contact', '(630) 555-0144')
            ->json('data.id');

        $this->actingAs($this->pm, 'api')->getJson($this->repBase()."/{$id}")
            ->assertOk()->assertJsonPath('data.name', 'Marco Ruiz');

        $this->actingAs($this->pm, 'api')->patchJson($this->repBase()."/{$id}", ['title' => 'Senior PE'])
            ->assertOk()->assertJsonPath('data.title', 'Senior PE');

        $this->actingAs($this->pm, 'api')->deleteJson($this->repBase()."/{$id}")->assertOk();

        $this->assertSoftDeleted('gc_representatives', ['id' => $id]);
        $this->actingAs($this->pm, 'api')->getJson($this->repBase()."/{$id}")->assertNotFound();
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->pm, 'api')->postJson($this->repBase(), ['title' => 'No name'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_foreman_can_read_but_not_write(): void
    {
        $rep = $this->makeRep();

        $this->actingAs($this->foreman, 'api')->getJson($this->repBase())->assertOk();

        $this->actingAs($this->foreman, 'api')->postJson($this->repBase(), ['name' => 'X'])->assertStatus(403);
        $this->actingAs($this->foreman, 'api')->patchJson($this->repBase()."/{$rep->id}", ['name' => 'X'])->assertStatus(403);
        $this->actingAs($this->foreman, 'api')->deleteJson($this->repBase()."/{$rep->id}")->assertStatus(403);
    }

    public function test_a_non_member_cannot_read_the_list(): void
    {
        $this->actingAs($this->globalOnly('Project Manager'), 'api')
            ->getJson($this->repBase())->assertStatus(403);
    }

    public function test_the_list_returns_only_that_gcs_representatives(): void
    {
        $this->rep->delete();   // the base fixture's representative; not under test here
        $other = $this->makeGc(['name' => 'Other GC Ltd']);
        $this->makeRep(null, ['name' => 'Zed Mine']);
        $this->makeRep(null, ['name' => 'Amy Mine']);
        $this->makeRep($other, ['name' => 'Not Mine']);
        $this->makeRep(null, ['name' => 'Retired'])->delete();

        $this->actingAs($this->foreman, 'api')->getJson($this->repBase())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Amy Mine')   // ordered by name
            ->assertJsonPath('data.1.name', 'Zed Mine');
    }

    public function test_a_representative_is_not_reachable_through_another_gc_or_project(): void
    {
        $rep = $this->makeRep();
        $other = $this->makeGc(['name' => 'Other GC Ltd']);

        $this->actingAs($this->pm, 'api')->getJson($this->repBase($other)."/{$rep->id}")->assertNotFound();

        $foreignProject = Project::factory()->create();
        $foreignGc = ProjectGeneralContractor::create([
            'project_id' => $foreignProject->id, 'name' => 'Foreign GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
        ]);

        // The GC belongs to another project, so it is not found under this one.
        $this->actingAs($this->pm, 'api')->getJson($this->repBase($foreignGc))->assertNotFound();
    }

    /* ---------------- capture ---------------- */

    public function test_capturing_with_a_representative_stores_the_link_and_a_snapshot(): void
    {
        $rep = $this->makeRep();

        $id = $this->capture(['gc_representative_id' => $rep->id])
            ->assertStatus(201)
            ->assertJsonPath('data.signatures.0.gc_representative_id', $rep->id)
            ->json('data.id');

        $sig = ChangeOrder::findOrFail($id)->currentSignature();
        $this->assertSame($rep->id, $sig->gc_representative_id);
        $this->assertSame('Dana Whitfield', $sig->signer_name);
        $this->assertSame('Site Superintendent', $sig->signer_title);
        $this->assertSame('Kellerman Construction Group', $sig->signer_company);
        $this->assertSame('(630) 555-0101', $sig->signer_contact);
    }

    public function test_typed_signer_details_are_refused_even_with_a_representative(): void
    {
        $this->capture([
            'gc_representative_id' => $this->makeRep()->id,
            'signer_name' => 'Someone Typed',
        ])->assertStatus(422)->assertJsonValidationErrors('signer_name');
    }

    public function test_contact_falls_back_to_email_when_there_is_no_phone(): void
    {
        $rep = $this->makeRep(null, ['phone' => null]);

        $id = $this->capture(['gc_representative_id' => $rep->id])->assertStatus(201)->json('data.id');

        $this->assertSame('dana.w@kellermangc.test', ChangeOrder::findOrFail($id)->currentSignature()->signer_contact);
    }

    public function test_editing_or_retiring_the_representative_does_not_change_the_signed_record(): void
    {
        $rep = $this->makeRep();
        $id = $this->capture(['gc_representative_id' => $rep->id])->assertStatus(201)->json('data.id');

        $this->actingAs($this->pm, 'api')->patchJson($this->repBase()."/{$rep->id}", [
            'name' => 'Renamed Person', 'title' => 'New Title', 'phone' => '000',
        ])->assertOk();
        $this->gc->update(['name' => 'Renamed GC Inc.']);
        $this->actingAs($this->pm, 'api')->deleteJson($this->repBase()."/{$rep->id}")->assertOk();

        $sig = ChangeOrder::findOrFail($id)->currentSignature();
        $this->assertSame('Dana Whitfield', $sig->signer_name);
        $this->assertSame('Site Superintendent', $sig->signer_title);
        $this->assertSame('Kellerman Construction Group', $sig->signer_company);
        $this->assertSame('(630) 555-0101', $sig->signer_contact);

        // The link still resolves to the retired row.
        $this->assertSame($rep->id, $sig->gcRepresentative->id);
        $this->assertTrue($sig->gcRepresentative->trashed());

        // And the work authorization sheet still renders.
        $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/authorization")->assertOk();
    }

    public function test_a_representative_of_another_gc_is_rejected(): void
    {
        $other = $this->makeGc(['name' => 'Other GC Ltd']);
        $rep = $this->makeRep($other);

        $this->capture([
            'gc_representative_id' => $rep->id,
            'general_contractor_id' => $this->gc->id,
        ])->assertStatus(422);

        $this->assertSame(0, ChangeOrder::count());
    }

    public function test_a_representative_from_another_project_is_rejected(): void
    {
        $foreignProject = Project::factory()->create();
        $foreignGc = ProjectGeneralContractor::create([
            'project_id' => $foreignProject->id, 'name' => 'Foreign GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
        ]);
        $rep = $this->makeRep($foreignGc);

        $this->capture(['gc_representative_id' => $rep->id])->assertStatus(422);
        $this->assertSame(0, ChangeOrder::count());
    }

    public function test_with_no_gc_sent_the_change_order_takes_the_representatives_gc(): void
    {
        // Not the primary — the rep's GC must win over the primary default.
        $other = $this->makeGc(['name' => 'Other GC Ltd']);
        $rep = $this->makeRep($other);

        $id = $this->capture(['gc_representative_id' => $rep->id])->assertStatus(201)->json('data.id');

        $co = ChangeOrder::findOrFail($id);
        $this->assertSame($other->id, $co->general_contractor_id);
        $this->assertSame('Other GC Ltd', $co->currentSignature()->signer_company);
    }

    public function test_a_retired_representative_cannot_be_picked(): void
    {
        $rep = $this->makeRep();
        $rep->delete();

        $this->capture(['gc_representative_id' => $rep->id])
            ->assertStatus(422)->assertJsonValidationErrors('gc_representative_id');
    }

    public function test_a_representative_is_required(): void
    {
        $this->capture([])->assertStatus(422)->assertJsonValidationErrors('gc_representative_id');
        $this->assertSame(0, ChangeOrder::count());
    }
}
