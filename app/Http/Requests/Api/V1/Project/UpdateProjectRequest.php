<?php

namespace App\Http\Requests\Api\V1\Project;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project')?->id ?? $this->route('project');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('projects', 'code')->ignore($projectId)->whereNull('deleted_at'),
            ],
            'name'            => ['sometimes', 'required', 'string', 'max:200'],
            'client_id'       => ['sometimes', 'required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_type_id' => ['sometimes', 'required', 'integer', Rule::exists('project_types', 'id')->whereNull('deleted_at')],
            'site_address'    => ['sometimes', 'nullable', 'string'],
            'budget'          => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999999999.99'],
            'start_date'      => ['sometimes', 'nullable', 'date'],
            'end_date'        => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
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
