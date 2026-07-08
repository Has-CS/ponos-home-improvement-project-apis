<?php

namespace App\Http\Requests\Api\V1\Lookup;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

abstract class StoreLookupRequest extends FormRequest
{
    abstract protected function table(): string;

    protected function supportsIsTerminal(): bool
    {
        return false;
    }

    protected function codeMaxLength(): int
    {
        return 40;
    }

    protected function labelMaxLength(): int
    {
        return 80;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'code' => [
                'required', 'string', "max:{$this->codeMaxLength()}",
                Rule::unique($this->table(), 'code')->whereNull('deleted_at'),
            ],
            'label' => ['required', 'string', "max:{$this->labelMaxLength()}"],
            // Not 'nullable': the column is NOT NULL DEFAULT 0. Omit the key
            // to accept the default; an explicit null must fail validation.
            'sort_order' => ['integer', 'min:0'],
            // is_system is server/seeder-controlled only, never settable via
            // the API — reject explicitly rather than silently ignoring it.
            'is_system' => ['prohibited'],
        ];

        $rules['is_terminal'] = $this->supportsIsTerminal() ? ['boolean'] : ['prohibited'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'is_system.prohibited' => 'is_system is managed by the system and cannot be set directly.',
            'is_terminal.prohibited' => 'is_terminal is not applicable to this lookup type.',
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
