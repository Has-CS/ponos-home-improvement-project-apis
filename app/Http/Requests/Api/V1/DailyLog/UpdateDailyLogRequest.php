<?php

namespace App\Http\Requests\Api\V1\DailyLog;

use App\Services\Attachment\AttachmentService;
use App\Services\DailyLog\DailyLogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDailyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'log_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'work_description' => ['sometimes', 'required', 'string'],
            'weather' => ['sometimes', 'nullable', 'string', 'max:80'],
            'crew_count' => ['sometimes', 'nullable', 'integer', 'min:0'],

            // Photos to ADD. Same per-item rule as the create path, so the two
            // cannot drift on accepted types or size.
            //
            // NOTE for clients: PHP does not parse multipart bodies on a real
            // PATCH request, so to send files here POST to this same URL with a
            // `_method=PATCH` field — Laravel's method-spoofing convention, and
            // the same workaround UserController documents for profile
            // pictures. A JSON PATCH (no files, or base64 strings) works as a
            // genuine PATCH.
            //
            // `max:5` bounds this batch; the REAL cap is the post-edit total,
            // which only the service can know — see
            // DailyLogService::syncPhotos().
            'photos' => ['sometimes', 'array', 'max:'.DailyLogService::MAX_PHOTOS],
            'photos.*' => ['required', AttachmentService::uploadRule()],

            // Photos to REMOVE, by attachment id. `exists` only proves the row
            // is a live attachment — it cannot prove the row belongs to THIS
            // log, so the service re-checks ownership. Same division of labour
            // as ChangeOrderService::assertGcInProject().
            'remove_photo_ids' => ['sometimes', 'array'],
            'remove_photo_ids.*' => [
                'integer',
                Rule::exists('attachments', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $log = $this->route('daily_log');
            if (! $log || ! $this->filled('log_date')) {
                return;
            }

            // Keep one live log per person/project/day when the date changes.
            $clash = \App\Models\DailyLog::query()
                ->where('project_id', $log->project_id)
                ->where('logged_by', $log->logged_by)
                ->whereDate('log_date', $this->input('log_date'))
                ->whereKeyNot($log->id)
                ->exists();

            if ($clash) {
                $v->errors()->add('log_date', 'Another daily log already exists for this date on this project.');
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
