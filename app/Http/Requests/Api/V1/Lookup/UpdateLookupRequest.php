<?php

namespace App\Http\Requests\Api\V1\Lookup;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

abstract class UpdateLookupRequest extends FormRequest
{
    abstract protected function table(): string;

    /** Route parameter name the model is bound to, e.g. 'gender', 'user_status'. */
    abstract protected function routeParam(): string;

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
        $model = $this->route($this->routeParam());

        $rules = [
            'code' => [
                'sometimes', 'required', 'string', "max:{$this->codeMaxLength()}",
                Rule::unique($this->table(), 'code')->whereNull('deleted_at')->ignore($model?->id),
            ],
            'label' => ['sometimes', 'required', 'string', "max:{$this->labelMaxLength()}"],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            // is_system is server/seeder-controlled only, never settable via
            // the API — reject explicitly rather than silently ignoring it.
            'is_system' => ['prohibited'],
        ];

        $rules['is_terminal'] = $this->supportsIsTerminal() ? ['sometimes', 'boolean'] : ['prohibited'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'is_system.prohibited' => 'is_system is managed by the system and cannot be set directly.',
            'is_terminal.prohibited' => 'is_terminal is not applicable to this lookup type.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $model = $this->route($this->routeParam());
            if (! $model || ! $model->is_system) {
                return;
            }

            if ($this->has('code') && (string) $this->input('code') !== (string) $model->code) {
                $v->errors()->add('code', 'This is a system-managed value; its code cannot be changed.');
            }

            if ($this->supportsIsTerminal() && $this->has('is_terminal')
                && (bool) $this->input('is_terminal') !== (bool) $model->is_terminal) {
                $v->errors()->add('is_terminal', 'This is a system-managed value; is_terminal cannot be changed.');
            }
        });
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
