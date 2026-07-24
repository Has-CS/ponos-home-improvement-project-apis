<?php

namespace App\Http\Requests\Api\V1\CostCode;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreCostCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('cost_codes', 'code')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
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
