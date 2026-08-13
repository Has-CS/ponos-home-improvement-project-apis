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
     * unit_id and trade_category_id are `nullable` here because both are
     * DERIVED from the catalog item when one is picked (see
     * MaterialRequestService::resolveLineAttributes()). They become mandatory
     * only on a free-text line, which lineChecks() enforces.
     *
     * cost_code_id is `nullable` for a different reason: it is not derivable at
     * all, it is simply not required at request time. Cost coding belongs to the
     * estimation phase and is not work the field can do — a PM/Admin fills it in
     * later, while the request is still editable. A value that IS supplied is
     * still validated against live cost_codes.
     *
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix = ''): array
    {
        return [
            "{$prefix}cost_code_id" => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
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
     * StoreMaterialRequestRequest so both paths report identically.
     *
     * Everything here is conditional on the line having NO catalog item: with
     * one, the trade category and unit are derived server-side and the DB's
     * item-or-description CHECK is already satisfied.
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

        // A-6 / DB CHECK: a line must identify the item somehow.
        if (empty($line['description'])) {
            $errors['description'] = 'A line must have either a catalog item or a description.';
        }

        // Neither can be derived without a catalog item behind the line.
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
