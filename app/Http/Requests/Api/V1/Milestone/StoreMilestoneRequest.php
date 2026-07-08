<?php
// StoreMilestoneRequest.php
namespace App\Http\Requests\Api\V1\Milestone;

use App\Models\Milestone;
use App\Models\ProjectUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('milestones', 'code')->where('project_id', $project->id)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'phase_id' => ['required', 'integer', Rule::exists('milestone_phases', 'id')->whereNull('deleted_at')],
            'status_id' => ['nullable', 'integer', Rule::exists('milestone_statuses', 'id')->whereNull('deleted_at')],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'planned_date' => ['nullable', 'date'],
            'actual_date' => ['nullable', 'date'],
            'predecessor_id' => ['nullable', 'integer', Rule::exists('milestones', 'id')->whereNull('deleted_at')],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'responsible_party_label' => ['nullable', 'string', 'max:150'],
            'deliverable' => ['nullable', 'string'],
            'is_payment_milestone' => ['nullable', 'boolean'],
            'payment_amount' => ['nullable', 'numeric', 'min:0', 'required_if:is_payment_milestone,true'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $project = $this->route('project');

            if ($this->filled('predecessor_id')) {
                $predecessor = Milestone::find((int) $this->input('predecessor_id'));
                if (! $predecessor || $predecessor->project_id !== $project->id) {
                    $v->errors()->add('predecessor_id', 'Predecessor must belong to the same project.');
                }
            }

            if ($this->filled('responsible_user_id')) {
                $isActiveMember = ProjectUser::where('project_id', $project->id)
                    ->where('user_id', $this->input('responsible_user_id'))
                    ->where('is_active', true)
                    ->exists();
                if (! $isActiveMember) {
                    $v->errors()->add('responsible_user_id', 'Responsible user must be an active member of this project.');
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
