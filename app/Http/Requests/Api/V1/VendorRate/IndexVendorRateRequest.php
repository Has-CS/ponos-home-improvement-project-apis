<?php

namespace App\Http\Requests\Api\V1\VendorRate;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexVendorRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as the literal text "true"/"false",
        // which Laravel's `boolean` rule does NOT accept (only 1/0/"1"/"0"/
        // true/false) — normalize here so ?current_only=false validates.
        if ($this->has('current_only')) {
            $this->merge(['current_only' => filter_var($this->query('current_only'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'catalog_item_id' => ['nullable', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            'current_only' => ['nullable', 'boolean'],

            // Free text over the item (name, SKU) and the vendor name.
            'search' => ['nullable', 'string', 'max:120'],

            // Every rate for items in one trade — "show me all plumbing rates".
            'trade_category_id' => ['nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],

            // Price band. `gte:rate_min` turns an inverted range into a clean
            // 422 instead of a silently empty list.
            'rate_min' => ['nullable', 'numeric', 'min:0'],
            'rate_max' => ['nullable', 'numeric', 'min:0', 'gte:rate_min'],

            // WHEN THE RATE WAS SET — "which prices moved in August". Named to
            // match the daily-log date filters, the codebase's only other range.
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],

            // WHAT WAS IN EFFECT ON A DATE — the estimating question, returning
            // one row per vendor as of that day.
            //
            // `prohibits:current_only` because the two contradict each other:
            // "current" is simply as_of = today, and combining them would either
            // silently ignore one or return an empty set. A 422 says so plainly.
            'as_of' => ['nullable', 'date', 'prohibits:current_only'],

            'sort_by' => ['nullable', Rule::in(['effective_from', 'rate', 'created_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
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
