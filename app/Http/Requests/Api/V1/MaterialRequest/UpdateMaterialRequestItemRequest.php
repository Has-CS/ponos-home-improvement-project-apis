<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateMaterialRequestItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `nullable`, not `required`: this is the endpoint a PM/Admin uses to
            // fill the cost code in after the fact, and it must also be possible
            // to clear one that was set in error.
            'cost_code_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'unit_id' => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'catalog_item_id' => ['sometimes', 'nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            'trade_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $item = $this->route('item');

            // Enforce the line's cross-field rules against the MERGED
            // post-update state, since any one field may be updated alone.
            $catalogItemId = $this->has('catalog_item_id') ? $this->input('catalog_item_id') : $item?->catalog_item_id;
            $description = $this->has('description') ? $this->input('description') : $item?->description;

            if (empty($catalogItemId) && empty($description)) {
                $v->errors()->add('description', 'A line must have either a catalog item or a description.');
            }

            // Converting a line to free text (or editing one that already is)
            // leaves nothing to derive the trade category from, so the merged
            // state must carry one. unit_id is deliberately not checked here:
            // the column is NOT NULL, so a live row always has a unit — the
            // `sometimes|required` rule above already stops it being unset.
            $tradeCategoryId = $this->has('trade_category_id')
                ? $this->input('trade_category_id')
                : $item?->trade_category_id;

            if (empty($catalogItemId) && empty($tradeCategoryId)) {
                $v->errors()->add('trade_category_id', 'A trade category is required when the line has no catalog item.');
            }
        });
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
