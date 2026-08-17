<?php

namespace App\Http\Requests\Api\V1\ChangeOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreChangeOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'scope' => ['nullable', 'string'],
            // Free text, one entry per line — see ChangeOrder::inclusionList().
            'inclusions' => ['nullable', 'string'],
            'exclusions' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:200'],
            // Existence only. That it belongs to THIS project is checked in
            // ChangeOrderService, which is the layer that knows the project.
            'general_contractor_id' => ['nullable', 'integer', Rule::exists('project_general_contractors', 'id')->whereNull('deleted_at')],
            'cost_code_id' => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'urgency_id' => ['nullable', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'value' => ['nullable', 'numeric', 'min:0'],

            ...self::scopeItemRules(),
        ];
    }

    /**
     * The Scope of Work table rows.
     *
     * Array order IS print order — `sort_order` is assigned server-side from the
     * index, and is deliberately not accepted from the client: two rows claiming
     * the same position would make the document's sequence ambiguous.
     *
     * Shared with UpdateChangeOrderRequest so the two can never drift; the only
     * difference there is a `sometimes` guard on the array itself, since PATCH
     * treats an absent key as "leave the rows alone".
     *
     * @return array<string,mixed>
     */
    public static function scopeItemRules(): array
    {
        return [
            'scope_items' => ['nullable', 'array'],
            'scope_items.*.row_type' => ['required', Rule::in(\App\Models\ChangeOrderScopeItem::ROW_TYPES)],

            // A spacer is a deliberate blank row; every other kind must say
            // something.
            'scope_items.*.label' => ['nullable', 'required_unless:scope_items.*.row_type,spacer', 'string', 'max:255'],

            'scope_items.*.quantity' => ['nullable', 'numeric', 'min:0'],

            // A quantity with no unit would print a bare number in a table whose
            // whole point is quantity + unit. Mirrored by a CHECK constraint, so
            // a direct write cannot bypass it either.
            'scope_items.*.unit_id' => [
                'nullable',
                'required_with:scope_items.*.quantity',
                'integer',
                Rule::exists('units', 'id')->whereNull('deleted_at'),
            ],

            'scope_items.*.indent' => ['nullable', 'integer', 'between:0,3'],
        ];
    }

    protected function failedValidation(Validator $v): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $v->errors(),
        ], 422));
    }
}
