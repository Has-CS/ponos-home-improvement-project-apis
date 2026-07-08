<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Authorization for creating users. We gate on the Spatie permission
     * 'manage_users'. Set to true temporarily if you haven't seeded
     * permissions yet, but DON'T ship it that way.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage_users') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name'    => ['required', 'string', 'max:80'],
            'last_name'     => ['required', 'string', 'max:80'],
            'email'         => [
                'required',
                'email:rfc,dns',
                'max:255',
                // email lives in user_credentials; ignore soft-deleted rows.
                Rule::unique('user_credentials', 'email')->whereNull('deleted_at'),
            ],
            'gender_id'     => ['nullable', 'integer', Rule::exists('genders', 'id')->whereNull('deleted_at')],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'picture'       => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'user_status_id' => ['nullable', 'integer', Rule::exists('user_statuses', 'id')->whereNull('deleted_at')],

            // Roles: array of role IDs (global/default). Any role can be set at
            // creation — it becomes this user's default job title; project staffing
            // can inherit it later (see ProjectAssignmentController::assign()).
            'role_ids'   => ['sometimes', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where(fn($q) => $q->where('guard_name', 'api')->whereNull('project_id'))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'      => 'This email is already registered.',
            'role_ids.*.exists' => 'One or more roles are invalid or are not global roles.',
        ];
    }

    /**
     * Return validation errors in our standard envelope.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
