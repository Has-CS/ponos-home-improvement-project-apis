<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\Urgency;
use App\Services\MaterialRequest\MaterialRequestPdfService;
use App\Services\Rbac\RoleAssignmentService;

/**
 * A material request carries a human-readable name alongside its request_no.
 *
 * Nullable on purpose — requests already exist without one, and every caller
 * that predates the field must keep working — so the untitled path is covered
 * here as deliberately as the titled one.
 */
class MaterialRequestTitleTest extends MaterialRequestLineTestCase
{
    private function show(int $mrId): array
    {
        return $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertOk()->json('data');
    }

    /** @param array<string,mixed> $query */
    private function index(array $query = []): array
    {
        return $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests?".http_build_query($query))
            ->assertOk()->json('data.items');
    }

    public function test_a_title_sent_on_create_is_stored_and_returned(): void
    {
        $this->joinProject();

        $id = $this->createDraft(['title' => 'Level 3 rough-in materials']);

        $this->assertSame('Level 3 rough-in materials', MaterialRequest::findOrFail($id)->title);
        $this->assertSame('Level 3 rough-in materials', $this->show($id)['title']);

        // The list payload carries it too — the buyer queue has to be readable
        // without opening every row.
        $this->assertSame('Level 3 rough-in materials', $this->index()[0]['title']);
    }

    public function test_a_request_can_still_be_created_without_a_title(): void
    {
        $this->joinProject();

        // The whole point of the column being nullable: nothing that worked
        // before this field existed may start failing.
        $id = $this->createDraft();

        $this->assertNull(MaterialRequest::findOrFail($id)->title);
        $this->assertArrayHasKey('title', $this->show($id));
        $this->assertNull($this->show($id)['title']);
    }

    public function test_the_title_can_be_edited_and_cleared(): void
    {
        $this->joinProject();
        $id = $this->createDraft(['title' => 'Original name']);

        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/material-requests/{$id}", ['title' => 'Corrected name'])
            ->assertOk();

        $this->assertSame('Corrected name', $this->show($id)['title']);

        // Explicit null clears it; absent would leave it alone.
        $this->actingAs($this->foreman, 'api')
            ->patchJson("/api/v1/projects/{$this->project->id}/material-requests/{$id}", ['title' => null])
            ->assertOk();

        $this->assertNull($this->show($id)['title']);
    }

    public function test_an_over_long_title_is_rejected(): void
    {
        $this->joinProject();

        $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'title' => str_repeat('a', 201),
            ],
        )->assertStatus(422)->assertJsonPath('errors.title.0', fn ($m) => is_string($m));
    }

    public function test_search_matches_the_title_as_well_as_the_request_number(): void
    {
        $this->joinProject();

        $matching = $this->createDraft(['title' => 'Scaffold planks for the east face']);
        $other = $this->createDraft(['title' => 'Rebar tie wire']);

        $items = $this->index(['search' => 'Scaffold']);

        $this->assertCount(1, $items);
        $this->assertSame($matching, $items[0]['id']);
        $this->assertNotSame($other, $items[0]['id']);

        // The original behaviour has to survive the added OR.
        $byNumber = $this->index(['search' => MaterialRequest::findOrFail($other)->request_no]);
        $this->assertCount(1, $byNumber);
        $this->assertSame($other, $byNumber[0]['id']);
    }

    /**
     * The search OR must stay INSIDE its own group. Ungrouped it would escape
     * the project_id constraint and leak another project's requests — which is
     * an access-control failure, not a filtering quirk.
     */
    public function test_a_title_search_cannot_reach_another_projects_requests(): void
    {
        $this->joinProject();
        $mine = $this->createDraft(['title' => 'Shared keyword here']);

        // A second project the actor is also staffed on, so the only thing
        // keeping its request out of the results is the project_id scope.
        $otherProject = Project::factory()->create();
        app(RoleAssignmentService::class)->assignProjectRole(
            $otherProject, $this->foreman, $this->role('Foreman'), null,
        );

        $theirs = (int) $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$otherProject->id}/material-requests",
            [
                'urgency_id' => Urgency::where('code', 'normal')->value('id'),
                'title' => 'Shared keyword here',
            ],
        )->assertStatus(201)->json('data.id');

        $items = $this->index(['search' => 'Shared keyword']);

        $this->assertCount(1, $items, 'The search must not cross the project scope.');
        $this->assertSame($mine, $items[0]['id']);
        $this->assertNotSame($theirs, $items[0]['id']);
    }

    public function test_search_still_respects_other_filters(): void
    {
        $this->joinProject();
        $this->createDraft(['title' => 'Filtered keyword']);

        $urgent = Urgency::where('code', 'critical')->value('id');

        // Same keyword, but the urgency filter excludes it — proves the OR did
        // not escape the status/urgency conditions either.
        $this->assertCount(0, $this->index(['search' => 'Filtered keyword', 'urgency_id' => $urgent]));
        $this->assertCount(1, $this->index(['search' => 'Filtered keyword']));
    }

    /** Same view-render helper the PDF suite uses, so this asserts the real template. */
    private function documentHtml(int $mrId): string
    {
        $svc = app(MaterialRequestPdfService::class);
        $mr = MaterialRequest::findOrFail($mrId)->load([
            'status', 'urgency', 'requester.credential', 'structuredBy', 'project.client',
            'items.catalogItem', 'items.unit', 'items.costCode', 'items.tradeCategory', 'photos',
        ]);

        return view('pdf.material-request', [
            'mr' => $mr,
            'company' => config('company'),
            'described' => $svc->describedItems($mr->request_text),
        ])->render();
    }

    public function test_the_document_prints_the_title_when_set(): void
    {
        $this->joinProject();

        $html = $this->documentHtml($this->createDraft(['title' => 'Level 3 rough-in materials']));

        $this->assertStringContainsString('Level 3 rough-in materials', $html);
        $this->assertStringContainsString('doc-subject', $html);
    }

    public function test_an_untitled_document_prints_exactly_as_before(): void
    {
        $this->joinProject();

        $html = $this->documentHtml($this->createDraft());

        // The @if guard: no element and no empty band when there is no title.
        $this->assertStringNotContainsString('<div class="doc-subject">', $html);
        $this->assertStringContainsString('MATERIAL REQUEST', $html);
    }
}
