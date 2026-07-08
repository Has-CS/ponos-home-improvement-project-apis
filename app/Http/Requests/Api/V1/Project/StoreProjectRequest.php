<?php

namespace App\Http\Requests\Api\V1\Project;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // route role:Admin guards

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('projects', 'code')->whereNull('deleted_at'),
            ],
            'name'              => ['required', 'string', 'max:200'],
            'client_id'         => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_type_id'   => ['required', 'integer', Rule::exists('project_types', 'id')->whereNull('deleted_at')],
            'project_status_id' => ['nullable', 'integer', Rule::exists('project_statuses', 'id')->whereNull('deleted_at')],
            'site_address'      => ['nullable', 'string'],
            'budget'            => ['nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'start_date'        => ['nullable', 'date'],
            'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return ['code.unique' => 'A project with this code already exists.'];
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
