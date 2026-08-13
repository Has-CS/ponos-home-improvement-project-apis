<?php

namespace App\Http\Requests\Api\V1\PurchaseOrderTerms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics. `body` stays non-nullable when sent — a terms set with
     * an empty body would resolve onto purchase orders and print nothing,
     * which is indistinguishable from having no terms at all but much harder
     * to diagnose. Delete the set instead.
     */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body' => ['sometimes', 'required', 'string'],
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
