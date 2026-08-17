<?php

namespace App\Http\Requests\Api\V1\ChangeOrderTerms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreChangeOrderTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Omit or send null for the company-wide default; send a project id
            // to create that project's override. "Only one per scope" is
            // enforced in the service (409) and by partial unique indexes.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // Each optional individually, but a set with all three empty would
            // print nothing and only get in the way of the default resolving —
            // so at least one must carry text.
            'payment_terms_body' => ['nullable', 'required_without_all:changes_body,acceptance_body', 'string'],
            'changes_body' => ['nullable', 'string'],
            'acceptance_body' => ['nullable', 'string'],
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
