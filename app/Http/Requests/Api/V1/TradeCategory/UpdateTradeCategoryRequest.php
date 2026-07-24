<?php

namespace App\Http\Requests\Api\V1\TradeCategory;

use App\Models\TradeCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateTradeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tradeCategory = $this->route('trade_category');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('trade_categories', 'id')->whereNull('deleted_at')],
            'name' => [
                'sometimes', 'required', 'string', 'max:120',
                Rule::unique('trade_categories', 'name')
                    ->where(fn ($q) => $q->where('parent_id', $this->input('parent_id', $tradeCategory->parent_id)))
                    ->whereNull('deleted_at')
                    ->ignore($tradeCategory->id),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $tradeCategory = $this->route('trade_category');

            if (! $this->filled('parent_id')) {
                return;
            }

            $parentId = (int) $this->input('parent_id');

            if ($parentId === $tradeCategory->id) {
                $v->errors()->add('parent_id', 'A trade category cannot be its own parent.');
            } elseif ($this->wouldCreateCycle($tradeCategory->id, $parentId)) {
                $v->errors()->add('parent_id', 'This parent would create a circular hierarchy.');
            }
        });
    }

    /**
     * Walk up the parent chain from $candidateParentId; a cycle exists if
     * $tradeCategoryId appears anywhere in that chain.
     */
    private function wouldCreateCycle(int $tradeCategoryId, int $candidateParentId): bool
    {
        $cursor = $candidateParentId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 500) {
            if ($cursor === $tradeCategoryId) {
                return true;
            }
            $cursor = TradeCategory::where('id', $cursor)->value('parent_id');
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
