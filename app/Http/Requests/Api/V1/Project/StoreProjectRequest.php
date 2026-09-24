<?php

namespace App\Http\Requests\Api\V1\Project;

use App\Models\Project;
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

            // Leads every purchase-order number for this project
            // (SJ-2026-09-00001). Optional: ProjectService derives the initials
            // of the name when it is left out. Uppercase letters and digits only,
            // so the number stays readable and splits cleanly on its dashes.
            'short_code' => [
                'nullable',
                'string',
                'max:'.Project::SHORT_CODE_MAX,
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('projects', 'short_code')->whereNull('deleted_at'),
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
