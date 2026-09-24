<?php

namespace App\Http\Requests\Api\V1\PurchaseOrder;

use App\Services\Attachment\AttachmentService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Supporting files attached to a purchase order, appended to the PDF the vendor
 * receives.
 *
 * PDFs are accepted alongside images because that is what vendors actually send:
 * a quote as a PDF, a rate confirmation as a screenshot. AttachmentService
 * re-checks the real MIME type of the bytes and, for a PDF, that it can actually
 * be merged — `mimes:` alone trusts the extension.
 */
class StorePurchaseOrderAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,pdf',
                // Kilobytes, quoting AttachmentService's own limit so the two
                // can never drift apart.
                'max:'.(int) (AttachmentService::MAX_BYTES / 1024),
            ],
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
