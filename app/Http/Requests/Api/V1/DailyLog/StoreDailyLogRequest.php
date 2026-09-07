<?php

namespace App\Http\Requests\Api\V1\DailyLog;

use App\Services\Attachment\AttachmentService;
use App\Services\DailyLog\DailyLogService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreDailyLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'log_date' => ['required', 'date', 'before_or_equal:today'],
            'work_description' => ['required', 'string'],
            'weather' => ['nullable', 'string', 'max:80'],
            'crew_count' => ['nullable', 'integer', 'min:0'],

            // Site photos, 5 at most — the cap is enforced HERE, server-side,
            // not left to the client. Each entry accepts either a multipart
            // upload (phone gallery picker) or a base64 / data-URI string
            // (on-device camera capture), the same pair material-request photos
            // take, so a client need not branch on which it has.
            //
            // NOTE: PHP's own upload_max_filesize / post_max_size cut in below
            // AttachmentService::MAX_BYTES and are what a real deployment hits
            // first — five 10 MB photos need post_max_size raised well past its
            // 8M default. uploadRule() reports that case as a clean 422 rather
            // than a type error.
            'photos' => ['nullable', 'array', 'max:'.DailyLogService::MAX_PHOTOS],
            'photos.*' => ['required', AttachmentService::uploadRule()],

            // Optional: raise a linked field issue in the same submission.
            'issue' => ['nullable', 'array'],
            'issue.title' => ['required_with:issue', 'string', 'max:200'],
            'issue.description' => ['nullable', 'string'],
            'issue.severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'issue.assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $project = $this->route('project');
            if (! $project || ! $this->filled('log_date')) {
                return;
            }

            // One live log per person, per project, per day.
            $exists = \App\Models\DailyLog::query()
                ->where('project_id', $project->id)
                ->where('logged_by', $this->user()->id)
                ->whereDate('log_date', $this->input('log_date'))
                ->exists();

            if ($exists) {
                $v->errors()->add('log_date', 'You have already filed a daily log for this date on this project.');
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
