<?php

namespace App\Http\Requests\Api\V1\Project;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class IndexProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'page'       => ['nullable', 'integer', 'min:1'],
            'search'     => ['nullable', 'string', 'max:120'],
            'status_id'  => ['nullable', 'integer', Rule::exists('project_statuses', 'id')->whereNull('deleted_at')],
            'client_id'  => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'type_id'    => ['nullable', 'integer', Rule::exists('project_types', 'id')->whereNull('deleted_at')],
            'sort_by'    => ['nullable', Rule::in(['name', 'code', 'created_at', 'start_date', 'budget'])],
            'sort_dir'   => ['nullable', Rule::in(['asc', 'desc'])],
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
