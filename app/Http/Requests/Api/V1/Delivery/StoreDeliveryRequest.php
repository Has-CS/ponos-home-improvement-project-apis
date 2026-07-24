<?php

namespace App\Http\Requests\Api\V1\Delivery;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bol_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'received_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity_received' => ['required', 'numeric', 'gt:0'],
            'items.*.quantity_accepted' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var PurchaseOrder|null $po */
            $po = $this->route('purchase_order');
            if (! $po) {
                return;
            }

            $validItemIds = $po->items()->pluck('id')->all();

            foreach ((array) $this->input('items', []) as $i => $line) {
                if (! in_array((int) ($line['purchase_order_item_id'] ?? 0), $validItemIds, true)) {
                    $v->errors()->add("items.{$i}.purchase_order_item_id", 'This line does not belong to the purchase order being received.');
                }
                if (isset($line['quantity_accepted'], $line['quantity_received'])
                    && (float) $line['quantity_accepted'] > (float) $line['quantity_received']) {
                    $v->errors()->add("items.{$i}.quantity_accepted", 'Accepted quantity cannot exceed received quantity.');
                }
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
