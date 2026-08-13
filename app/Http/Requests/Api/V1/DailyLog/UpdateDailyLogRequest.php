<?php

namespace App\Http\Requests\Api\V1\DailyLog;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
