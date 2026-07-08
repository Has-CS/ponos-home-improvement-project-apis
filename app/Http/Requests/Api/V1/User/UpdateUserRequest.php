<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level role:Admin middleware handles authorization
    }

    public function rules(): array
    {
        // Route model binding param is {user}; get its id for the unique rule.
        $userId = $this->route('user')?->id ?? $this->route('user');

        return [
            'first_name'     => ['sometimes', 'required', 'string', 'max:80'],
            'last_name'      => ['sometimes', 'required', 'string', 'max:80'],
            'gender_id'      => ['sometimes', 'nullable', 'integer', Rule::exists('genders', 'id')->whereNull('deleted_at')],
            'date_of_birth'  => ['sometimes', 'nullable', 'date', 'before:today'],
            'picture'        => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'user_status_id' => ['sometimes', 'required', 'integer', Rule::exists('user_statuses', 'id')->whereNull('deleted_at')],

            // Email lives in user_credentials; ignore this user's own row + soft-deleted rows.
            'email' => [
                'sometimes',
                'required',
                'email:rfc,dns',
                'max:255',
                Rule::unique('user_credentials', 'email')
                    ->where(fn($q) => $q->whereNull('deleted_at'))
                    ->ignore($userId, 'user_id'),
            ],

            // Roles: array of role IDs (global). Empty array = remove all global roles.
            'role_ids'   => ['sometimes', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(fn($q) => $q->where('guard_name', 'api')->whereNull('project_id'))],

            // Direct permissions: array of permission NAMES.
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.*.exists'    => 'One or more roles are invalid or are not global roles.',
            'permissions.*.exists' => 'One or more permissions are invalid.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
