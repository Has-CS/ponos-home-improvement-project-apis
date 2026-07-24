<?php

namespace App\Http\Requests\Api\V1\Issue;

use App\Models\ProjectUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'severity' => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'issue_status_id' => ['sometimes', 'required', 'integer', Rule::exists('issue_statuses', 'id')->whereNull('deleted_at')],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $project = $this->route('project');
            if ($project && $this->filled('assigned_to')) {
                $isMember = ProjectUser::where('project_id', $project->id)
                    ->where('user_id', $this->input('assigned_to'))
                    ->where('is_active', true)
                    ->exists();
                if (! $isMember) {
                    $v->errors()->add('assigned_to', 'The assignee must be an active member of this project.');
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
