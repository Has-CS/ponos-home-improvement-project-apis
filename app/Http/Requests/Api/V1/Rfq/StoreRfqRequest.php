<?php

namespace App\Http\Requests\Api\V1\Rfq;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: an RFQ is pre-project by default, but may be linked to
            // an existing project when quoting against already-won work.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // Required: an RFQ always targets exactly one real vendor row, since
            // the vendor's email is what the document gets sent to.
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],

            'title' => ['required', 'string', 'max:200'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // Optional convenience: create the RFQ with its lines in one call.
            'items' => ['nullable', 'array'],
            ...StoreRfqItemRequest::lineRules('items.*.'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('items', []) as $i => $item) {
                foreach (StoreRfqItemRequest::lineChecks((array) $item) as $field => $message) {
                    $v->errors()->add("items.{$i}.{$field}", $message);
                }
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
