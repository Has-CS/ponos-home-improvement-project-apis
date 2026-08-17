<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderScopeItem;
use App\Models\Unit;

/**
 * The Scope of Work quantity table.
 *
 * Rows carry what the work is and how much of it — never what it costs. A change
 * order's money is the single `value` decimal, so there is no amount column and
 * the subtotal row is a label rather than a sum.
 *
 * The behaviour most likely to surprise a caller is REPLACE-on-update: sending
 * `scope_items` swaps the whole set, omitting the key leaves it alone, and []
 * clears it. Several tests below pin exactly that.
 */
class ChangeOrderScopeItemsTest extends ChangeOrderTestCase
{
    private function unitId(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    /** The table from the client's reference sheet, trimmed to its distinct shapes. */
    private function referenceRows(): array
    {
        return [
            ['row_type' => 'section', 'label' => 'DIVISION 09- FINISHES'],
            ['row_type' => 'group', 'label' => 'OPENINGS'],
            ['row_type' => 'emphasis', 'label' => 'TEMPORARY DOORS'],
            ['row_type' => 'line', 'label' => 'Installation of Temporary doors and Hardware',
                'quantity' => 3, 'unit_id' => $this->unitId('ea'), 'indent' => 1],
            ['row_type' => 'spacer'],
            ['row_type' => 'emphasis', 'label' => 'WALL', 'quantity' => 44, 'unit_id' => $this->unitId('lf')],
            ['row_type' => 'subline', 'label' => '500 Tape Roll',
                'quantity' => 0.7, 'unit_id' => $this->unitId('roll'), 'indent' => 2],
            ['row_type' => 'subtotal', 'label' => 'Subtotal (Finishes)'],
        ];
    }

    private function itemsOf(int $coId)
    {
        return ChangeOrder::findOrFail($coId)->scopeItems()->with('unit')->get();
    }

    /* ---------------- creating ---------------- */

    public function test_a_change_order_can_be_raised_with_a_scope_table(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $rows = $this->itemsOf($id);

        $this->assertCount(8, $rows);
        $this->assertSame(
            ['section', 'group', 'emphasis', 'line', 'spacer', 'emphasis', 'subline', 'subtotal'],
            $rows->pluck('row_type')->all(),
        );
    }

    public function test_array_order_becomes_print_order(): void
    {
        // sort_order is assigned server-side from the array index — the client
        // never sends it, so two rows can never claim one position.
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $this->itemsOf($id)->pluck('sort_order')->all());
        $this->assertSame('DIVISION 09- FINISHES', $this->itemsOf($id)->first()->label);
        $this->assertSame('Subtotal (Finishes)', $this->itemsOf($id)->last()->label);
    }

    public function test_quantities_and_units_print_as_the_reference_does(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $tape = $this->itemsOf($id)->firstWhere('label', '500 Tape Roll');

        // Trailing zeros trimmed: 0.700 reads as 0.7, matching the sheet.
        $this->assertSame('0.7', $tape->printedQuantity());
        // Upper-cased code, not units.label ("Roll") which would not fit.
        $this->assertSame('ROLL', $tape->printedUnit());
    }

    public function test_a_change_order_without_a_scope_table_is_unaffected(): void
    {
        $id = $this->createDraftAs($this->foreman);

        $this->assertCount(0, $this->itemsOf($id));
    }

    /* ---------------- replace semantics ---------------- */

    public function test_sending_scope_items_replaces_the_whole_set(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);
        $originalIds = $this->itemsOf($id)->pluck('id')->all();

        $this->updateAs($this->foreman, $id, ['scope_items' => [
            ['row_type' => 'line', 'label' => 'Only line now', 'quantity' => 5, 'unit_id' => $this->unitId('sf')],
        ]])->assertOk();

        $rows = $this->itemsOf($id);
        $this->assertCount(1, $rows);
        $this->assertSame('Only line now', $rows->first()->label);

        // Superseded rows are soft-deleted, not destroyed — a mistaken overwrite
        // stays recoverable from the database.
        foreach ($originalIds as $oldId) {
            $this->assertSoftDeleted('change_order_scope_items', ['id' => $oldId]);
        }
    }

    public function test_omitting_the_key_leaves_the_table_alone(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $this->updateAs($this->foreman, $id, ['title' => 'Retitled only'])->assertOk();

        $this->assertCount(8, $this->itemsOf($id));
    }

    public function test_an_empty_array_clears_the_table(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $this->updateAs($this->foreman, $id, ['scope_items' => []])->assertOk();

        $this->assertCount(0, $this->itemsOf($id));
    }

    /* ---------------- validation ---------------- */

    public function test_a_quantity_without_a_unit_is_rejected(): void
    {
        // A bare number in a table whose whole point is quantity + unit.
        $this->actingAs($this->foreman, 'api')->postJson($this->base(), [
            'title' => 'Bad row',
            'scope_items' => [['row_type' => 'line', 'label' => 'No unit', 'quantity' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('scope_items.0.unit_id');
    }

    public function test_an_unknown_row_type_is_rejected(): void
    {
        $this->actingAs($this->foreman, 'api')->postJson($this->base(), [
            'title' => 'Bad row',
            'scope_items' => [['row_type' => 'heading', 'label' => 'Nope']],
        ])->assertStatus(422)->assertJsonValidationErrors('scope_items.0.row_type');
    }

    public function test_every_row_but_a_spacer_needs_a_label(): void
    {
        $this->actingAs($this->foreman, 'api')->postJson($this->base(), [
            'title' => 'Bad row',
            'scope_items' => [['row_type' => 'line', 'quantity' => 5, 'unit_id' => $this->unitId('sf')]],
        ])->assertStatus(422)->assertJsonValidationErrors('scope_items.0.label');

        // A spacer is a deliberate blank row, so it needs none.
        $this->actingAs($this->foreman, 'api')->postJson($this->base(), [
            'title' => 'Spacer only',
            'scope_items' => [['row_type' => 'spacer']],
        ])->assertStatus(201);
    }

    public function test_a_spacer_never_keeps_a_stray_label(): void
    {
        // Honouring one would print text in a row that exists to be blank.
        $id = $this->createDraftAs($this->foreman, [
            'scope_items' => [['row_type' => 'spacer', 'label' => 'should not print']],
        ]);

        $this->assertNull($this->itemsOf($id)->first()->label);
    }

    public function test_a_unit_on_a_row_with_no_quantity_is_dropped(): void
    {
        // Otherwise a heading row would print "— SF".
        $id = $this->createDraftAs($this->foreman, [
            'scope_items' => [['row_type' => 'group', 'label' => 'DRYWALL', 'unit_id' => $this->unitId('sf')]],
        ]);

        $this->assertNull($this->itemsOf($id)->first()->unit_id);
    }

    public function test_indent_is_bounded(): void
    {
        $this->actingAs($this->foreman, 'api')->postJson($this->base(), [
            'title' => 'Too deep',
            'scope_items' => [['row_type' => 'line', 'label' => 'Deep', 'indent' => 9]],
        ])->assertStatus(422)->assertJsonValidationErrors('scope_items.0.indent');
    }

    /* ---------------- the freeze ---------------- */

    public function test_the_table_cannot_be_changed_once_the_document_exists(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign', ['scope_items' => $this->referenceRows()]);

        $this->updateAs($this->admin, $id, ['scope_items' => []])->assertStatus(409);

        // Same edit-matrix rule that already freezes value, inclusions and
        // exclusions once a PDF has been filed.
        $this->assertCount(8, $this->itemsOf($id));
    }

    public function test_a_pm_can_correct_the_table_during_review(): void
    {
        $id = $this->changeOrderAt('pending_pm', ['scope_items' => $this->referenceRows()]);

        $this->updateAs($this->pm, $id, ['scope_items' => [
            ['row_type' => 'line', 'label' => 'Corrected by PM', 'quantity' => 12, 'unit_id' => $this->unitId('sf')],
        ]])->assertOk();

        $this->assertSame('Corrected by PM', $this->itemsOf($id)->first()->label);
    }

    /* ---------------- exposure ---------------- */

    public function test_the_table_is_exposed_on_the_detail_endpoint(): void
    {
        $id = $this->createDraftAs($this->foreman, ['scope_items' => $this->referenceRows()]);

        $this->actingAs($this->foreman, 'api')->getJson($this->base()."/{$id}")
            ->assertOk()
            ->assertJsonCount(8, 'data.scope_items')
            ->assertJsonPath('data.scope_items.0.row_type', 'section')
            ->assertJsonPath('data.scope_items.0.label', 'DIVISION 09- FINISHES')
            ->assertJsonPath('data.scope_items.6.printed_unit', 'ROLL')
            ->assertJsonPath('data.scope_items.6.printed_quantity', '0.7');
    }

    /* ---------------- the document ---------------- */

    public function test_the_table_prints_on_the_document(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign', ['scope_items' => $this->referenceRows()]);

        $html = $this->documentHtml($id);

        $this->assertStringContainsString('DIVISION 09- FINISHES', $html);
        $this->assertStringContainsString('TEMPORARY DOORS', $html);
        $this->assertStringContainsString('500 Tape Roll', $html);
        $this->assertStringContainsString('ROLL', $html);
        $this->assertStringContainsString('Subtotal (Finishes)', $html);
        // Every distinct row treatment reached the sheet.
        foreach (['si-section', 'si-group', 'si-emphasis', 'si-subline', 'si-spacer', 'si-subtotal'] as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }

    public function test_no_table_prints_when_there_are_no_rows(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        // A change order raised before this feature existed prints as it did.
        //
        // Asserting on the MARKUP, not the bare class name: `.scope-items` also
        // appears in the stylesheet, which renders whether or not any row does,
        // so the looser check would pass for the wrong reason.
        $this->assertStringNotContainsString('<table class="scope-items"', $this->documentHtml($id));
    }

    public function test_a_long_table_paginates(): void
    {
        $rows = [['row_type' => 'section', 'label' => 'DIVISION 09- FINISHES']];
        for ($i = 1; $i <= 70; $i++) {
            $rows[] = ['row_type' => 'line', 'label' => "Work item {$i}",
                'quantity' => $i, 'unit_id' => $this->unitId('sf'), 'indent' => 1];
        }

        $id = $this->changeOrderAt('pending_counter_sign', ['scope_items' => $rows]);

        $bytes = app(\App\Services\ChangeOrder\ChangeOrderPdfService::class)
            ->render(ChangeOrder::findOrFail($id));

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1, substr_count($bytes, '/Type /Page'));
    }

    public function test_the_row_type_constraint_is_enforced_by_the_database(): void
    {
        $id = $this->createDraftAs($this->foreman);

        // The FormRequest is not the only guard — a direct write is refused too.
        $this->expectException(\Illuminate\Database\QueryException::class);

        ChangeOrderScopeItem::create([
            'change_order_id' => $id,
            'row_type' => 'not_a_type',
            'label' => 'Bypass attempt',
            'sort_order' => 0,
        ]);
    }

    private function documentHtml(int $coId): string
    {
        $co = ChangeOrder::with([
            'type', 'status', 'gcDecision', 'costCode', 'urgency', 'originator',
            'counterSignedBy', 'gcDecisionBy', 'project.client', 'generalContractor',
            'scopeItems.unit', 'signatures.capturedBy',
        ])->findOrFail($coId);

        return view('pdf.change-order', [
            'co' => $co,
            'company' => config('company'),
            'logoSrc' => null,
        ])->render();
    }
}
