<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Accepted shape of a mobile number, shared with UpdateUserRequest so the
     * two cannot drift — the same reason SearchCatalogItemRequest::baseRules()
     * and StoreMaterialRequestItemRequest::lineRules() are shared rather than
     * duplicated.
     *
     * Deliberately permissive: digits, spaces, hyphens, parentheses and an
     * optional leading `+`, 7 to 20 characters. It has to accept the local US
     * formatting the company writes its OWN number in — config/company.php
     * stores "(203) 491-4431" — so demanding E.164 would reject the house
     * style. It still rejects letters and anything too short to be a number.
     */
    public const MOBILE_NUMBER_REGEX = '/^\+?[0-9\s\-()]{7,20}$/';

    /**
     * The field rules for a mobile number, minus the presence rule, which
     * differs between create (`required`) and update (`sometimes`,`required`).
     *
     * `max:40` mirrors the column width; the regex is the stricter of the two
     * and is what actually governs the format.
     *
     * @return array<int, mixed>
     */
    public static function mobileNumberRules(): array
    {
        return ['string', 'max:40', 'regex:'.self::MOBILE_NUMBER_REGEX];
    }

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

            // Mandatory on create even though the column is nullable — see the
            // migration: existing users predate the field and cannot be given
            // an honest number retroactively, so the requirement starts here
            // rather than in the schema.
            'mobile_number' => ['required', ...self::mobileNumberRules()],
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
            'email.unique'         => 'This email is already registered.',
            'mobile_number.regex'  => 'Enter a valid mobile number, e.g. (203) 491-4431 or +1 203 491 4431.',
            'role_ids.*.exists'    => 'One or more roles are invalid or are not global roles.',
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
