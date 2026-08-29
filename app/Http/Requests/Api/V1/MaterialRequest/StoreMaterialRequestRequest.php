<?php

namespace App\Http\Requests\Api\V1\MaterialRequest;

use App\Services\Attachment\AttachmentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
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
     * Uploads are checked here so the caller gets a clean, per-item 422 naming
     * the offending index. Base64 strings are only shape-checked here — their
     * mime and size are validated when decoded, in AttachmentService, since
     * that is the only place the actual bytes exist.
     */
    private static function photoRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value instanceof UploadedFile) {
                if (! $value->isValid()) {
                    // PHP rejects anything over upload_max_filesize BEFORE Laravel
                    // sees it, handing us an invalid file rather than none — say so
                    // plainly instead of emitting a confusing type error.
                    $fail('The :attribute failed to upload. It may exceed the server upload limit.');

                    return;
                }

                if (! isset(AttachmentService::ALLOWED_MIME[strtolower((string) $value->getMimeType())])) {
                    $fail('The :attribute must be a PNG or JPEG image.');

                    return;
                }

                if ($value->getSize() > AttachmentService::MAX_BYTES) {
                    $fail('The :attribute exceeds the '.(int) (AttachmentService::MAX_BYTES / 1_048_576).' MB limit.');

                    return;
                }

                if ($value->getSize() === 0) {
                    $fail('The :attribute is empty.');
                }

                return;
            }

            if (! is_string($value)) {
                $fail('The :attribute must be an uploaded image file or a base64-encoded image.');
            }
        };
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
