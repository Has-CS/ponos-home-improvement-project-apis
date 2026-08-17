<?php

namespace App\Http\Requests\Api\V1\ChangeOrderTerms;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateChangeOrderTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Re-scoping is allowed (default → project, or between projects); the
            // service re-checks for a collision at the target scope.
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // No required_without_all here, unlike the store request: a PATCH
            // touching one body must not be judged against the two it did not
            // send. Clearing all three leaves an empty set, which prints nothing
            // — recoverable by editing, unlike a create that never should have
            // existed.
            'payment_terms_body' => ['sometimes', 'nullable', 'string'],
            'changes_body' => ['sometimes', 'nullable', 'string'],
            'acceptance_body' => ['sometimes', 'nullable', 'string'],
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
