<?php

namespace App\Http\Requests\Api\V1\Delivery;

use App\Models\Delivery;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_item_id' => ['nullable', 'integer'],
            'discrepancy_type_id' => ['required', 'integer', Rule::exists('discrepancy_types', 'id')->whereNull('deleted_at')],
            'description' => ['required', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var Delivery|null $delivery */
            $delivery = $this->route('delivery');
            if (! $delivery || ! $this->filled('delivery_item_id')) {
                return;
            }

            $belongs = $delivery->items()->whereKey($this->input('delivery_item_id'))->exists();
            if (! $belongs) {
                $v->errors()->add('delivery_item_id', 'This line does not belong to the delivery.');
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
