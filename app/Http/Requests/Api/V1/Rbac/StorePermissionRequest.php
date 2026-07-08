<?php
// app/Http/Requests/Api/V1/Rbac/StorePermissionRequest.php
namespace App\Http\Requests\Api\V1\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:125',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('permissions', 'name')->where('guard_name', 'api'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'  => 'Permission names use lowercase, digits and underscores only.',
            'name.unique' => 'That permission already exists.',
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
