<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\Project;
use App\Models\ProjectGeneralContractor;
use Illuminate\Support\Facades\Storage;

/**
 * General Contractors on a project, and how a change order binds to one.
 *
 * The headline guarantee is the snapshot: once a change order's document has
 * been prepared, editing or retiring the GC record must not alter what that
 * change order says or what its filed PDF shows. Everything else here exists to
 * protect that.
 */
class ChangeOrderGeneralContractorTest extends ChangeOrderTestCase
{
    private function gcBase(): string
    {
        return "/api/v1/projects/{$this->project->id}/general-contractors";
    }

    /** @param array<string,mixed> $payload */
    private function createGcAs(\App\Models\User $user, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'api')->postJson($this->gcBase(), [
            'name' => 'Brightwater Builders LLC',
            'street_1' => '19 Kingsway',
            'city' => 'Aurora',
            ...$payload,
        ]);
    }

    /* ---------------- CRUD ---------------- */

    public function test_a_pm_can_add_and_list_general_contractors(): void
    {
        $this->createGcAs($this->pm)
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Brightwater Builders LLC');

        $this->actingAs($this->foreman, 'api')->getJson($this->gcBase())
            ->assertOk()
            // The setUp GC plus the one just added, primary first.
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_primary', true);
    }

    public function test_the_address_prints_as_formatted_lines(): void
    {
        $this->actingAs($this->pm, 'api')->getJson($this->gcBase())
            ->assertOk()
            ->assertJsonPath('data.0.address_lines', [
                '4400 Commerce Drive',
                'Suite 210',
                'Naperville, IL 60563',
                'United States',
            ]);
    }

    public function test_the_first_general_contractor_becomes_primary_automatically(): void
    {
        // A project with no GC at all — the setUp project already has one.
        $other = Project::factory()->create();
        $gc = app(\App\Services\ProjectGeneralContractor\ProjectGeneralContractorService::class)
            ->create($other, ['name' => 'Solo GC', 'street_1' => '1 High St', 'city' => 'Naperville'], $this->pm->id);

        // Not asked for, granted anyway: otherwise a project can hold GCs with
        // no default and the dropdown pre-selection never fires.
        $this->assertTrue($gc->is_primary);
    }

    public function test_promoting_a_general_contractor_demotes_the_incumbent(): void
    {
        $second = $this->makeGc(['name' => 'Second GC']);

        $this->actingAs($this->pm, 'api')
            ->patchJson($this->gcBase()."/{$second->id}", ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        // The partial unique index guarantees at most one; prove the old one let go.
        $this->assertFalse($this->gc->fresh()->is_primary);
        $this->assertSame(1, ProjectGeneralContractor::where('project_id', $this->project->id)
            ->where('is_primary', true)->count());
    }

    public function test_the_last_primary_cannot_simply_be_cleared(): void
    {
        $this->actingAs($this->pm, 'api')
            ->patchJson($this->gcBase()."/{$this->gc->id}", ['is_primary' => false])
            ->assertStatus(422);
    }

    public function test_deleting_the_primary_promotes_the_next_survivor(): void
    {
        $second = $this->makeGc(['name' => 'Second GC']);

        $this->actingAs($this->pm, 'api')
            ->deleteJson($this->gcBase()."/{$this->gc->id}")
            ->assertOk();

        $this->assertSoftDeleted('project_general_contractors', ['id' => $this->gc->id]);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_a_general_contractor_from_another_project_is_not_reachable(): void
    {
        $other = Project::factory()->create();
        $foreign = ProjectGeneralContractor::create([
            'project_id' => $other->id, 'name' => 'Foreign GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
        ]);

        $this->actingAs($this->pm, 'api')
            ->patchJson($this->gcBase()."/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertStatus(404);
    }

    /* ---------------- route gates ---------------- */

    public function test_writes_require_edit_project(): void
    {
        // A foreman is a project member and can READ the list, but GC records are
        // project master data — same gate as PATCH /projects/{project}.
        $this->createGcAs($this->foreman)->assertStatus(403);

        $this->actingAs($this->foreman, 'api')->getJson($this->gcBase())->assertOk();
    }

    public function test_a_non_member_cannot_read_the_list(): void
    {
        $this->actingAs($this->globalOnly('Site Engineer'), 'api')
            ->getJson($this->gcBase())
            ->assertStatus(403);
    }

    /* ---------------- binding a change order ---------------- */

    public function test_a_change_order_defaults_to_the_projects_primary_general_contractor(): void
    {
        $id = $this->createDraftAs($this->foreman);

        $this->assertSame($this->gc->id, ChangeOrder::findOrFail($id)->general_contractor_id);
    }

    public function test_a_change_order_can_name_a_specific_general_contractor(): void
    {
        $second = $this->makeGc(['name' => 'Second GC']);

        $id = $this->createDraftAs($this->foreman, ['general_contractor_id' => $second->id]);

        $this->assertSame($second->id, ChangeOrder::findOrFail($id)->general_contractor_id);
    }

    public function test_another_projects_general_contractor_is_rejected(): void
    {
        $other = Project::factory()->create();
        $foreign = ProjectGeneralContractor::create([
            'project_id' => $other->id, 'name' => 'Foreign GC',
            'street_1' => '1 Elsewhere', 'city' => 'Chicago',
        ]);

        // The FormRequest can only check the row exists; the service checks it
        // belongs here. Without this, one client's counterparty could be printed
        // on another's paperwork.
        $this->actingAs($this->foreman, 'api')
            ->postJson($this->base(), ['title' => 'Cross-project', 'general_contractor_id' => $foreign->id])
            ->assertStatus(422);

        $id = $this->createDraftAs($this->foreman);
        $this->updateAs($this->foreman, $id, ['general_contractor_id' => $foreign->id])
            ->assertStatus(422);
    }

    public function test_the_document_cannot_be_prepared_without_a_general_contractor(): void
    {
        // The document is addressed to the GC, so this is the same class of
        // guard as the value check.
        $this->gc->delete();

        $id = $this->createDraftAs($this->foreman, ['value' => 5000.00]);
        $this->assertNull(ChangeOrder::findOrFail($id)->general_contractor_id);

        $this->submitAs($this->foreman, $id)->assertOk();
        $this->validateAs($this->pm, $id)->assertOk();
        $this->approveAs($this->admin, $id)->assertOk();

        $this->prepareAs($this->pm, $id)->assertStatus(422);
        $this->assertSame('pending_document', $this->statusOf($id));
    }

    /* ---------------- the snapshot ---------------- */

    public function test_the_snapshot_is_taken_when_the_document_is_prepared(): void
    {
        $id = $this->changeOrderAt('pending_document');

        // Nothing frozen yet — the detail endpoint reads through to the live row.
        $this->assertFalse(ChangeOrder::findOrFail($id)->hasGcSnapshot());

        $this->prepareAs($this->pm, $id)->assertOk();

        $co = ChangeOrder::findOrFail($id);
        $this->assertTrue($co->hasGcSnapshot());
        $this->assertSame($this->gc->name, $co->gc_name);
        $this->assertSame($this->gc->contact_name, $co->gc_contact_name);
        $this->assertSame($this->gc->city, $co->gc_city);
        $this->assertSame($this->gc->email, $co->gc_email);
    }

    public function test_editing_the_gc_afterwards_does_not_alter_the_change_order(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');   // prepared already

        $before = ChangeOrder::findOrFail($id)->gcBlock();

        $this->gc->update([
            'name' => 'Renamed Contractors Inc.',
            'street_1' => '999 Somewhere Else',
            'city' => 'Peoria',
            'contact_name' => 'Someone New',
        ]);

        $after = ChangeOrder::findOrFail($id)->gcBlock();

        // The whole point of the snapshot.
        $this->assertSame($before, $after);
        $this->assertSame('Kellerman Construction Group', $after['name']);
        $this->assertNotSame($this->gc->fresh()->name, $after['name']);
    }

    public function test_retiring_the_gc_does_not_break_an_issued_change_order(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->actingAs($this->pm, 'api')->deleteJson($this->gcBase()."/{$this->gc->id}")->assertOk();

        // Soft-deleted, so the FK still resolves and the snapshot still prints.
        $co = ChangeOrder::findOrFail($id);
        $this->assertSame('Kellerman Construction Group', $co->gcBlock()['name']);

        $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
    }

    public function test_the_filed_pdf_is_unchanged_by_a_later_gc_edit(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $doc = \App\Models\Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $id)->where('attachment_type', 'document')->firstOrFail();
        $filed = Storage::disk($doc->disk)->get($doc->file_path);

        $this->gc->update(['name' => 'Renamed Contractors Inc.']);

        // Stored bytes, not a re-render — what the GC was sent stays retrievable.
        $response = $this->actingAs($this->pm, 'api')->get($this->base()."/{$id}/pdf")->assertOk();
        $this->assertSame($filed, $response->getContent());
    }

    /* ---------------- inclusions & exclusions ---------------- */

    public function test_inclusions_and_exclusions_round_trip_as_lists(): void
    {
        $id = $this->createDraftAs($this->foreman, [
            'inclusions' => "All framing and blocking\nTemporary shoring",
            'exclusions' => "Permit fees\nAsbestos abatement\n\nAny work outside grid C4-C7",
        ]);

        $this->actingAs($this->foreman, 'api')->getJson($this->base()."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.inclusion_list', ['All framing and blocking', 'Temporary shoring'])
            // Blank lines collapse — an author may separate entries with single
            // or double newlines and get the same result.
            ->assertJsonPath('data.exclusion_list', ['Permit fees', 'Asbestos abatement', 'Any work outside grid C4-C7']);
    }

    public function test_inclusions_and_exclusions_print_on_the_document(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign', [
            'inclusions' => 'All framing and blocking',
            'exclusions' => 'Permit fees',
        ]);

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('Inclusions', $html);
        $this->assertStringContainsString('All framing and blocking', $html);
        $this->assertStringContainsString('Permit fees', $html);
    }

    public function test_the_section_collapses_entirely_when_both_are_empty(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        // A change order raised before these fields existed must print exactly
        // as it did before — no empty heading over nothing.
        $this->assertStringNotContainsString('Inclusions &amp; exclusions', $this->documentHtml($id));
    }

    private function documentHtml(int $coId): string
    {
        $co = ChangeOrder::with([
            'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
            'counterSignedBy', 'gcDecisionBy', 'project.client', 'generalContractor',
            'approvals.actor', 'approvals.fromStatus', 'approvals.toStatus',
            'signatures.capturedBy',
        ])->findOrFail($coId);

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }
}
