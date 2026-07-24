<?php

namespace App\Http\Requests\Api\V1\Issue;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'status_id' => ['nullable', 'integer', Rule::exists('issue_statuses', 'id')->whereNull('deleted_at')],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
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
