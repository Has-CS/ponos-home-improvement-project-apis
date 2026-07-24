<?php

namespace App\Http\Requests\Api\V1\VendorRate;

use App\Models\VendorRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreVendorRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->whereNull('deleted_at')],
            'catalog_item_id' => ['required', 'integer', Rule::exists('catalog_items', 'id')->whereNull('deleted_at')],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')->whereNull('deleted_at')],
            'rate' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->filled('vendor_id') || ! $this->filled('catalog_item_id')) {
                return;
            }

            $current = VendorRate::where('vendor_id', $this->input('vendor_id'))
                ->where('catalog_item_id', $this->input('catalog_item_id'))
                ->whereNull('effective_to')
                ->first();

            if (! $current) {
                return;
            }

            $effectiveFrom = $this->input('effective_from') ?? now()->toDateString();

            if ($effectiveFrom <= $current->effective_from->toDateString()) {
                $v->errors()->add(
                    'effective_from',
                    'A new rate must be effective after the current rate\'s effective date ('.$current->effective_from->toDateString().').'
                );
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
