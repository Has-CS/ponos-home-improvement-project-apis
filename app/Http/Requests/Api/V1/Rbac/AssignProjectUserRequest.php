<?php

namespace App\Http\Requests\Api\V1\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class AssignProjectUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'  => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            // Optional: omit to default to the user's existing global role.
            'role_id'  => [
                'sometimes',
                'integer',
                Rule::exists('roles', 'id')->where(fn($q) =>
                $q->where('guard_name', 'api')->whereNull('project_id')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'The specified user does not exist.',
            'role_id.exists' => 'The specified role does not exist.',
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
