<?php

namespace App\Http\Requests\Api\V1\DailyLog;

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
