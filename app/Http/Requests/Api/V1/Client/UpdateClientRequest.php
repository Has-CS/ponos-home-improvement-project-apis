<?php

namespace App\Http\Requests\Api\V1\Client;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['sometimes', 'required', 'string', 'max:160'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email'        => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone'        => ['sometimes', 'nullable', 'string', 'max:40'],
            'address'      => ['sometimes', 'nullable', 'string'],
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
