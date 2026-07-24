<?php

namespace App\Http\Requests\Api\V1\CostCode;

use App\Models\CostCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCostCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $costCode = $this->route('cost_code');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('cost_codes', 'id')->whereNull('deleted_at')],
            'code' => [
                'sometimes', 'required', 'string', 'max:40',
                Rule::unique('cost_codes', 'code')->whereNull('deleted_at')->ignore($costCode->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $costCode = $this->route('cost_code');

            if (! $this->filled('parent_id')) {
                return;
            }

            $parentId = (int) $this->input('parent_id');

            if ($parentId === $costCode->id) {
                $v->errors()->add('parent_id', 'A cost code cannot be its own parent.');
            } elseif ($this->wouldCreateCycle($costCode->id, $parentId)) {
                $v->errors()->add('parent_id', 'This parent would create a circular hierarchy.');
            }
        });
    }

    /**
     * Walk up the parent chain from $candidateParentId; a cycle exists if
     * $costCodeId appears anywhere in that chain.
     */
    private function wouldCreateCycle(int $costCodeId, int $candidateParentId): bool
    {
        $cursor = $candidateParentId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 500) {
            if ($cursor === $costCodeId) {
                return true;
            }
            $cursor = CostCode::where('id', $cursor)->value('parent_id');
        }

        return false;
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
