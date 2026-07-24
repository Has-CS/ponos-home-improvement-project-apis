<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreMaterialRequestItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared field rules for a single material-request line — reused by the
     * nested items[] array in StoreMaterialRequestRequest.
     *
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix = ''): array
    {
        return [
            "{$prefix}cost_code_id" => ['required', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            "{$prefix}unit_id" => ['required', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            "{$prefix}catalog_item_id" => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            "{$prefix}trade_category_id" => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            "{$prefix}description" => ['nullable', 'string', 'max:255'],
            "{$prefix}quantity" => ['required', 'numeric', 'gt:0'],
            "{$prefix}notes" => ['nullable', 'string'],
            "{$prefix}sort_order" => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function rules(): array
    {
        return self::lineRules();
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // DB CHECK: a line must identify the item somehow (A-6).
            if (! $this->filled('catalog_item_id') && ! $this->filled('description')) {
                $v->errors()->add('description', 'A line must have either a catalog item or a description.');
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
