<?php

namespace App\Http\Requests\Api\V1\Rfq;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreRfqItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared field rules for a single RFQ line — reused by the nested items[]
     * array in StoreRfqRequest. Same shape as
     * StoreMaterialRequestItemRequest::lineRules(), minus cost_code_id: an RFQ
     * is pre-project, and cost coding doesn't exist at that stage.
     *
     * unit_id and trade_category_id are `nullable` here because both are
     * DERIVED from the catalog item when one is picked (see
     * RfqService::resolveLineAttributes()). They become mandatory only on a
     * free-text line, which lineChecks() enforces.
     *
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix = ''): array
    {
        return [
            "{$prefix}unit_id" => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            "{$prefix}catalog_item_id" => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            "{$prefix}trade_category_id" => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            "{$prefix}description" => ['nullable', 'string', 'max:255'],
            "{$prefix}quantity" => ['required', 'numeric', 'gt:0'],
            "{$prefix}notes" => ['nullable', 'string'],
            "{$prefix}sort_order" => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Cross-field checks for one line, shared with the nested items[] array in
     * StoreRfqRequest so both paths report identically.
     *
     * @param  array<string,mixed>  $line
     * @return array<string,string> field name => message
     */
    public static function lineChecks(array $line): array
    {
        if (! empty($line['catalog_item_id'])) {
            return [];
        }

        $errors = [];

        if (empty($line['description'])) {
            $errors['description'] = 'A line must have either a catalog item or a description.';
        }

        if (empty($line['trade_category_id'])) {
            $errors['trade_category_id'] = 'A trade category is required when the line has no catalog item.';
        }

        if (empty($line['unit_id'])) {
            $errors['unit_id'] = 'A unit is required when the line has no catalog item.';
        }

        return $errors;
    }

    public function rules(): array
    {
        return self::lineRules();
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach (self::lineChecks($this->all()) as $field => $message) {
                $v->errors()->add($field, $message);
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
