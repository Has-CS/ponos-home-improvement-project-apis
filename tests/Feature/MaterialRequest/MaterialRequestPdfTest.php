<?php

namespace Tests\Feature\MaterialRequest;

use App\Models\CatalogItem;
use App\Models\Project;
use App\Models\Urgency;
use App\Models\User;
use App\Services\MaterialRequest\MaterialRequestPdfService;
use App\Services\Rbac\RoleAssignmentService;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The printable material-request document.
 *
 * Access deliberately mirrors show(): both sit behind project.access, so if you
 * can view the request you can print it. The PDF must never become a looser
 * path to the same data.
 *
 * Setup helpers live in MaterialRequestLineTestCase.
 */
class MaterialRequestPdfTest extends MaterialRequestLineTestCase
{
    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole($user, $this->role($roleName));

        return $user;
    }

    private function pdfAs(User $user, int $mrId, string $query = '', ?Project $project = null): TestResponse
    {
        $project ??= $this->project;

        return $this->actingAs($user, 'api')
            ->get("/api/v1/projects/{$project->id}/material-requests/{$mrId}/pdf{$query}");
    }

    /** A request with one catalog line and one free-text line. */
    private function requestWithMixedLines(): int
    {
        return $this->createDraft([
            'notes' => 'Needed before the pour on Friday.',
            'items' => [
                [
                    'catalog_item_id' => CatalogItem::factory()->tradeCategory('Electrical')->create([
                        'name' => 'Electric Wires', 'sku' => 'EW-E1_64',
                    ])->id,
                    'quantity' => 15,
                    'notes' => 'For Electricity',
                ],
                [
                    'trade_category_id' => $this->tradeCategoryId('Framing & Carpentry'),
                    'unit_id' => $this->unitId('ea'),
                    'description' => '2x 8ft pressure-treated 4x4',
                    'quantity' => 2,
                ],
            ],
        ]);
    }

    /* ---------------- it renders ---------------- */

    public function test_a_project_member_can_download_the_pdf(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->joinProject();

        $response = $this->pdfAs($this->foreman, $mrId)->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        // Real PDF bytes, not an error page rendered with the wrong header.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_the_filename_is_the_request_number(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->joinProject();

        $requestNo = \App\Models\MaterialRequest::findOrFail($mrId)->request_no;

        $this->assertStringContainsString(
            "filename=\"{$requestNo}.pdf\"",
            $this->pdfAs($this->foreman, $mrId)->assertStatus(200)->headers->get('Content-Disposition'),
        );
    }

    public function test_it_displays_inline_by_default_and_downloads_on_request(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->joinProject();

        $this->assertStringStartsWith(
            'inline;',
            $this->pdfAs($this->foreman, $mrId)->headers->get('Content-Disposition'),
        );

        $this->assertStringStartsWith(
            'attachment;',
            $this->pdfAs($this->foreman, $mrId, '?download=1')->headers->get('Content-Disposition'),
        );
    }

    /* ---------------- access matches the view endpoint ---------------- */

    public function test_a_non_member_cannot_download_the_pdf(): void
    {
        $mrId = $this->requestWithMixedLines();
        // Deliberately NOT joining the project.

        $this->pdfAs($this->foreman, $mrId)->assertStatus(403);
    }

    public function test_the_pdf_is_no_looser_than_the_view_endpoint(): void
    {
        $mrId = $this->requestWithMixedLines();
        $outsider = $this->userWithRole('Project Manager');

        // A PM who is not a member of this project: blocked on both, identically.
        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}")
            ->assertStatus(403);

        $this->pdfAs($outsider, $mrId)->assertStatus(403);
    }

    public function test_a_request_from_another_project_is_not_found(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->joinProject();

        $otherProject = Project::factory()->create();
        app(RoleAssignmentService::class)->assignProjectRole(
            $otherProject, $this->foreman, $this->role('Foreman'), null,
        );

        $this->pdfAs($this->foreman, $mrId, '', $otherProject)->assertStatus(404);
    }

    /* ---------------- every status prints ---------------- */

    public function test_a_draft_prints(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->joinProject();

        // Draft is printable — it just carries a watermark on the page.
        $this->pdfAs($this->foreman, $mrId)->assertStatus(200);
    }

    public function test_a_request_under_review_prints_with_its_approval_chain(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);
        $this->actingAs($this->userWithRole('Project Manager'), 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/approve")
            ->assertStatus(200);

        $this->joinProject();

        $this->pdfAs($this->foreman, $mrId)->assertStatus(200);
    }

    public function test_a_prose_only_request_with_no_lines_prints(): void
    {
        // The empty-items branch of the template — a free-text request the office
        // has not structured yet.
        $mrId = $this->createDraft([
            'request_text' => "1. Portland Cement - 50 Bags\n2. Fine Sand - 10 Tons",
        ]);
        $this->joinProject();

        $this->pdfAs($this->foreman, $mrId)->assertStatus(200);
    }

    /* ---------------- content ---------------- */

    /**
     * The document's HTML, built exactly as the service builds it.
     *
     * Asserting on HTML rather than the PDF stream is deliberate: dompdf's
     * output is compressed and not greppable, so byte assertions would prove
     * nothing beyond "it is a PDF" — which the render test already covers.
     */
    private function documentHtml(int $mrId): string
    {
        $svc = app(MaterialRequestPdfService::class);
        $mr = \App\Models\MaterialRequest::findOrFail($mrId)->load([
            'status', 'urgency', 'requester.credential', 'structuredBy', 'project.client',
            'items.catalogItem', 'items.unit', 'items.costCode', 'items.tradeCategory', 'photos',
        ]);

        return view('pdf.material-request', [
            'mr' => $mr,
            'company' => config('company'),
            'described' => $svc->describedItems($mr->request_text),
        ])->render();
    }

    public function test_the_document_carries_the_request_and_project_details(): void
    {
        $mrId = $this->requestWithMixedLines();
        $html = $this->documentHtml($mrId);
        $mr = \App\Models\MaterialRequest::findOrFail($mrId);

        $this->assertStringContainsString($mr->request_no, $html);
        $this->assertStringContainsString('MATERIAL REQUEST', $html);
        $this->assertStringContainsString(config('company.name'), $html);
        $this->assertStringContainsString($this->project->name, $html);

        // Both line shapes render.
        $this->assertStringContainsString('Electric Wires', $html);
        $this->assertStringContainsString('EW-E1_64', $html);
        $this->assertStringContainsString('2x 8ft pressure-treated 4x4', $html);
        $this->assertStringContainsString('For Electricity', $html);
        $this->assertStringContainsString('Needed before the pour on Friday.', $html);

        // A material request carries no money, and its readers do not hold
        // view_pricing — no price column may ever appear on this document.
        $this->assertStringNotContainsString('Unit price', $html);
        $this->assertStringNotContainsString('Subtotal', $html);
    }

    public function test_a_draft_renders_a_watermark(): void
    {
        $html = $this->documentHtml($this->requestWithMixedLines());

        $this->assertStringContainsString('class="watermark"', $html);
        $this->assertStringContainsString('DRAFT', $html);
    }

    /* ---------------- page geometry (dompdf workarounds) ---------------- */

    public function test_page_margins_are_on_the_body_not_the_page_rule(): void
    {
        // This dompdf build ignores @page margins outright — with them there the
        // document prints to the paper edge. Pinning it because the symptom
        // (content against the trim) is easy to misdiagnose as a content problem.
        $html = $this->documentHtml($this->requestWithMixedLines());

        $this->assertMatchesRegularExpression('/@page\s*\{[^}]*margin:\s*0;/s', $html);
        $this->assertMatchesRegularExpression('/\bbody\s*\{[^}]*margin:\s*12mm 12mm 18mm 12mm;/s', $html);
    }

    public function test_fixed_boxes_are_inset_rather_than_edge_to_edge(): void
    {
        // dompdf positions fixed boxes against the PAGE box, not the content
        // box, so the footer and watermark must restate the margin themselves.
        $html = $this->documentHtml($this->requestWithMixedLines());

        $this->assertMatchesRegularExpression('/\.doc-footer\s*\{[^}]*left:\s*12mm;\s*right:\s*12mm;/s', $html);
        $this->assertMatchesRegularExpression('/\.watermark\s*\{[^}]*left:\s*12mm;\s*right:\s*12mm;/s', $html);
    }

    /* ---------------- removed sections ---------------- */

    public function test_the_approval_chain_and_signature_block_are_not_printed(): void
    {
        $mrId = $this->requestWithMixedLines();
        $this->actingAs($this->foreman, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/material-requests/{$mrId}/submit")
            ->assertStatus(200);

        $html = $this->documentHtml($mrId);

        // Both were dropped: this is an internal working document and the chain
        // is already on screen in the app.
        $this->assertStringNotContainsString('Approval chain', $html);
        $this->assertStringNotContainsString('class="chain"', $html);
        $this->assertStringNotContainsString('class="signatures"', $html);
        $this->assertStringNotContainsString('sig-line', $html);
    }

    /* ---------------- requested-description list ---------------- */

    public function test_a_numbered_description_renders_as_a_list(): void
    {
        $mrId = $this->createDraft([
            'request_text' => '1. Portland Cement - 50 Bags - Required for the foundation of Block A. '
                .'2. Fine Sand - 10 Tons - Required for mortar preparation. '
                .'3. Binding Wire - 50 Kg - Required for tying reinforcement bars.',
        ]);

        $html = $this->documentHtml($mrId);

        $this->assertStringContainsString('Requested description (as submitted)', $html);
        $this->assertStringContainsString('Portland Cement — 50 Bags', $html);
        $this->assertStringContainsString('Required for the foundation of Block A.', $html);
        $this->assertStringContainsString('Binding Wire — 50 Kg', $html);
        // Rendered as rows, not one cramped paragraph.
        $this->assertStringContainsString('class="d-no"', $html);
    }

    public function test_a_non_list_description_prints_verbatim(): void
    {
        $text = "Need cement and sand for the foundation.\nAsk the site engineer for exact quantities.";
        $mrId = $this->createDraft(['request_text' => $text]);

        $html = $this->documentHtml($mrId);

        $this->assertStringContainsString('Need cement and sand for the foundation.', $html);
        $this->assertStringContainsString('Ask the site engineer for exact quantities.', $html);
        $this->assertStringNotContainsString('class="d-no"', $html);
    }

    /* ---------------- the parser, on its own ---------------- */

    #[DataProvider('descriptionCases')]
    public function test_the_description_parser_is_defensive(string $label, string $input, int $expectedItems, array $mustContain): void
    {
        $parsed = app(MaterialRequestPdfService::class)->describedItems($input);

        $this->assertCount($expectedItems, $parsed['items'], $label);

        // Whatever the shape, no input text may be lost.
        $rendered = ($parsed['intro'] ?? '').' '.collect($parsed['items'])
            ->map(fn ($i) => $i['no'].' '.$i['head'].' '.($i['rest'] ?? ''))
            ->implode(' ');

        foreach ($mustContain as $needle) {
            $this->assertStringContainsString($needle, $rendered, "{$label}: lost '{$needle}'");
        }
    }

    public static function descriptionCases(): array
    {
        return [
            'clean numbered list' => [
                'clean numbered list',
                '1. Cement - 50 Bags - For foundation. 2. Sand - 10 Tons - For mortar.',
                2,
                ['Cement', '50 Bags', 'For foundation.', 'Sand', 'For mortar.'],
            ],
            'decimals must not split' => [
                'decimals must not split',
                '1. Rebar 12mm - 1.5 Tons - For columns. 2. Rebar 16mm - 2.25 Tons - For beams.',
                2,
                ['1.5 Tons', '2.25 Tons'],
            ],
            'sentence-ending letters must not split' => [
                'sentence-ending letters must not split',
                '1. Cement - 50 Bags - For the foundation of Block A. 2. Sand - 10 Tons - For mortar.',
                2,
                ['Block A.'],
            ],
            'inconsistent numbering is preserved as written' => [
                'inconsistent numbering is preserved as written',
                '1. Cement - 50 Bags. 3. Sand - 10 Tons. 7) Wire - 50 Kg.',
                3,
                ['Cement', 'Sand', 'Wire'],
            ],
            'text before the first marker is kept' => [
                'text before the first marker is kept',
                'For Block A ground floor: 1. Cement - 50 Bags. 2. Sand - 10 Tons.',
                2,
                ['For Block A ground floor:', 'Cement', 'Sand'],
            ],
            'items without the qty/reason shape still print whole' => [
                'items without the qty/reason shape still print whole',
                '1. Binding wire enough for the whole slab 2. Some extra shims',
                2,
                ['Binding wire enough for the whole slab', 'Some extra shims'],
            ],
            'ragged spacing is tolerated' => [
                'ragged spacing is tolerated',
                "1.   Cement  -  50 Bags\n\n2.Sand - 10 Tons\n3.  Wire - 50 Kg",
                2, // "2.Sand" has no space after the dot, so it stays inside item 1
                ['Cement', 'Sand', 'Wire'],
            ],
            'not a list at all' => [
                'not a list at all',
                'Need cement and sand, ask the site engineer for quantities.',
                0,
                ['Need cement and sand, ask the site engineer for quantities.'],
            ],
            'single numbered item is not treated as a list' => [
                'single numbered item is not treated as a list',
                '1. Cement - 50 Bags - For the foundation.',
                0,
                ['1. Cement - 50 Bags - For the foundation.'],
            ],
        ];
    }

    public function test_the_parser_handles_empty_input(): void
    {
        $parsed = app(MaterialRequestPdfService::class)->describedItems(null);

        $this->assertNull($parsed['intro']);
        $this->assertSame([], $parsed['items']);
    }

    public function test_the_service_names_the_file_after_the_request(): void
    {
        $mr = \App\Models\MaterialRequest::findOrFail($this->createDraft([
            'items' => [['catalog_item_id' => CatalogItem::factory()->create()->id, 'quantity' => 1]],
        ]));

        $this->assertSame(
            $mr->request_no.'.pdf',
            app(MaterialRequestPdfService::class)->fileName($mr),
        );
    }
}
