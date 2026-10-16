<?php

namespace App\Http\Requests\Api\V1\ChangeOrder;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GcQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'pm_direct' is the one the queue exists for: a project manager sent it
            // to the General Contractor without Admin review.
            'gc_sent_via' => ['nullable', Rule::in(['pm_direct', 'admin_reviewed'])],
            'status_id' => ['nullable', 'integer', Rule::exists('change_order_statuses', 'id')->whereNull('deleted_at')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],

            // Work built, payment refused. Booleans arrive as query strings, so
            // 'boolean' is what accepts "1"/"true"/"0" without a 422 on the common
            // case — the same handling the other index requests use.
            'disputed' => ['nullable', 'boolean'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
