<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    /** Longest date range a single request may aggregate over. */
    public const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query-string booleans arrive as "true"/"false" text, which the
        // `boolean` rule rejects — same normalization as IndexPendingRequestsRequest.
        if ($this->has('refresh')) {
            $this->merge([
                'refresh' => filter_var($this->query('refresh'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Existence only; whether the caller may see it is the service's
            // 403, so an inaccessible project is distinguishable from a typo.
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'upcoming_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'refresh' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $v) {
                $from = $this->input('date_from');
                $to = $this->input('date_to');
                if ($from && $to && ! $v->errors()->hasAny(['date_from', 'date_to'])
                    && Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_RANGE_DAYS) {
                    $v->errors()->add('date_to', 'The date range may not exceed '.self::MAX_RANGE_DAYS.' days.');
                }
            },
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
