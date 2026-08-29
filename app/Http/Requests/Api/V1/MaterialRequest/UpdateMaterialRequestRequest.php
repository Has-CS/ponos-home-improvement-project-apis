<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Editable for as long as the request itself is, unlike
            // request_text below: the title is a label anyone may correct, not
            // the requester's original message. Absent = leave alone, explicit
            // null = clear it, same as notes.
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],

            'urgency_id' => ['sometimes', 'required', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'needed_by_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            // Accepted only while the request is still with the foreman —
            // MaterialRequestService::update() rejects it with a 422 afterwards,
            // so the original message survives as the record Procurement works
            // from. Photos are add-at-create-time only for now.
            'request_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
