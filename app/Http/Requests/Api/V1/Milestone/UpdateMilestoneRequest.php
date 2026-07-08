<?php
// UpdateMilestoneRequest.php
namespace App\Http\Requests\Api\V1\Milestone;

use App\Models\Milestone;
use App\Models\ProjectUser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateMilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('project');
        $milestone = $this->route('milestone');

        return [
            'code' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('milestones', 'code')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at')
                    ->ignore($milestone->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'phase_id' => ['sometimes', 'required', 'integer', Rule::exists('milestone_phases', 'id')->whereNull('deleted_at')],
            'status_id' => ['sometimes', 'required', 'integer', Rule::exists('milestone_statuses', 'id')->whereNull('deleted_at')],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            // planned_date / actual_date are purely informational now — no
            // "null = reopen" semantic. Reopening is done via status_id.
            'planned_date' => ['sometimes', 'nullable', 'date'],
            'actual_date' => ['sometimes', 'nullable', 'date'],
            'predecessor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('milestones', 'id')->whereNull('deleted_at')],
            'responsible_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'responsible_party_label' => ['sometimes', 'nullable', 'string', 'max:150'],
            'deliverable' => ['sometimes', 'nullable', 'string'],
            'is_payment_milestone' => ['sometimes', 'boolean'],
            'payment_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $project = $this->route('project');
            $milestone = $this->route('milestone');

            if ($this->filled('predecessor_id')) {
                $predecessorId = (int) $this->input('predecessor_id');

                if ($predecessorId === $milestone->id) {
                    $v->errors()->add('predecessor_id', 'A milestone cannot be its own predecessor.');
                } else {
                    $predecessor = Milestone::find($predecessorId);
                    if (! $predecessor || $predecessor->project_id !== $project->id) {
                        $v->errors()->add('predecessor_id', 'Predecessor must belong to the same project.');
                    } elseif ($this->wouldCreateCycle($milestone->id, $predecessorId)) {
                        $v->errors()->add('predecessor_id', 'This predecessor would create a circular dependency.');
                    }
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

            // required_if against the MERGED state (existing + incoming), since
            // is_payment_milestone/payment_amount may be updated independently.
            $isPayment = $this->has('is_payment_milestone')
                ? $this->boolean('is_payment_milestone')
                : (bool) $milestone->is_payment_milestone;

            $amount = $this->has('payment_amount')
                ? $this->input('payment_amount')
                : $milestone->payment_amount;

            if ($isPayment && $amount === null) {
                $v->errors()->add('payment_amount', 'Payment amount is required when this is a payment milestone.');
            }
        });
    }

    /**
     * Walk up the predecessor chain from $candidatePredecessorId; a cycle
     * exists if $milestoneId appears anywhere in that chain.
     */
    private function wouldCreateCycle(int $milestoneId, int $candidatePredecessorId): bool
    {
        $cursor = $candidatePredecessorId;
        $guard = 0;

        while ($cursor !== null && $guard++ < 500) {
            if ($cursor === $milestoneId) {
                return true;
            }
            $cursor = Milestone::where('id', $cursor)->value('predecessor_id');
        }

        return false;
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
