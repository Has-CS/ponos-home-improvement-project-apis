<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use App\Services\Attachment\AttachmentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One photo: either an uploaded file or a base64 / data-URI string.
     *
     * Uploads are checked so the caller gets a clean, per-item 422 naming the
     * offending index. Base64 strings are only shape-checked — their mime and
     * size are validated when decoded, in AttachmentService, since that is the
     * only place the actual bytes exist.
     *
     * The rule itself now lives on AttachmentService, which owns the
     * ALLOWED_MIME and MAX_BYTES it is written against and is shared with the
     * daily-log photo field. Kept as a named method here so the call site below
     * reads unchanged.
     */
    private static function photoRule(): \Closure
    {
        return AttachmentService::uploadRule();
    }

    public function rules(): array
    {
        return [
            // A short name for the request. Optional server-side so the existing
            // untitled requests and any caller that predates this field keep
            // working; the form is where it is made mandatory.
            'title' => ['nullable', 'string', 'max:200'],

            'urgency_id' => ['required', 'integer', Rule::exists('urgencies', 'id')->whereNull('deleted_at')],
            'needed_by_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],

            // The "just describe it" path for field users who can't work the
            // catalog pickers. Independent of items[] — a request may carry
            // prose, structured lines, or both. Requiring at least one of the
            // two is enforced at SUBMIT, not here, so a draft can still be
            // started empty exactly as it could before.
            'request_text' => ['nullable', 'string', 'max:5000'],

            // Photos accept EITHER shape on the same field, so a client can send
            // whichever suits it: a multipart file upload (browser file picker,
            // Postman) or a base64 / data-URI string in a JSON body (on-device
            // camera capture). See lineChecksPhoto() for the per-item rule.
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['required', self::photoRule()],

            // Optional convenience: create the request with its lines in one call.
            'items' => ['nullable', 'array'],
            ...StoreMaterialRequestItemRequest::lineRules('items.*.'),
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('items', []) as $i => $item) {
                foreach (StoreMaterialRequestItemRequest::lineChecks((array) $item) as $field => $message) {
                    $v->errors()->add("items.{$i}.{$field}", $message);
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
