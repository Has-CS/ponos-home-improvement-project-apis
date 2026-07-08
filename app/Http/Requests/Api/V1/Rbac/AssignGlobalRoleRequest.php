<?php

namespace App\Http\Requests\Api\V1\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AssignGlobalRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    } // route role middleware guards

    public function rules(): array
    {
        return [
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(fn($q) =>
                $q->where('guard_name', 'api')->whereNull('project_id')),
            ],
        ];
    }

    public function messages(): array
    {
        return ['role_id.exists' => 'The specified global role does not exist.'];
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
