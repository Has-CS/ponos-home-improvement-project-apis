<?php

namespace App\Services\Activity;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writer for the central audit spine (`activity_logs`).
 *
 * Deliberately generic — it knows nothing about material requests. Any module
 * that needs "who changed what, and from what to what" can call it.
 *
 * The first use is reviewer edits on material requests: once a request is under
 * review, a PM or Admin can alter someone else's lines, so each change has to
 * leave a trace.
 */
class ActivityLogger
{
    /**
     * Record one significant action.
     *
     * @param  string  $event    Dotted verb, e.g. "material_request_item.updated".
     * @param  Model   $subject  The entity the change belongs to. For child rows,
     *                           prefer the PARENT as the subject and carry the
     *                           child's id in $properties — that keeps "the whole
     *                           history of X" a single indexed lookup on
     *                           (subject_type, subject_id) rather than a JSON scan.
     * @param  array<string,mixed>  $properties
     */
    public function log(
        string $event,
        Model $subject,
        ?User $causer,
        array $properties = [],
        ?int $projectId = null,
        ?string $description = null,
    ): Activity {
        return Activity::create([
            'log_name' => 'default',
            'event' => $event,
            'description' => $description,
            'causer_id' => $causer?->id,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'project_id' => $projectId,
            'properties' => $properties,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
        ]);
    }

    /**
     * The old/new pair for a model that has just been saved.
     *
     * Ordering matters and is easy to get wrong: save() calls syncOriginal(),
     * so getOriginal() AFTER a save returns the new values. The caller must
     * therefore capture $before (getAttributes()) BEFORE fill(), and pass it in
     * here afterwards. getChanges() is then authoritative on what actually
     * changed, and we intersect to recover the matching old values.
     *
     * @param  array<string,mixed>  $before  Attributes captured before the change.
     * @return array{old: array<string,mixed>, new: array<string,mixed>}
     */
    public function diff(Model $model, array $before): array
    {
        $changes = $model->getChanges();

        // Bookkeeping columns are noise in an audit diff.
        unset($changes['updated_at'], $changes['created_at']);

        $keys = array_keys($changes);

        return [
            'old' => $this->snapshot($model, array_intersect_key($before, $changes), $keys),
            'new' => $this->snapshot($model, $model->getAttributes(), $keys),
        ];
    }

    /**
     * Read attributes back through the model's own casts.
     *
     * Without this the two halves of a diff are formatted differently and can't
     * be compared: the OLD value comes from the database ("10.000" for a
     * decimal:3 column) while the NEW one is whatever the caller assigned (the
     * integer 15), because getChanges() reports raw set values, not cast ones.
     * Running both sides through a model instance makes them symmetrical.
     *
     * @param  array<string,mixed>  $attributes  Raw attributes to read from.
     * @param  array<int,string>|null  $only     Restrict to these keys.
     * @return array<string,mixed>
     */
    public function snapshot(Model $model, array $attributes, ?array $only = null): array
    {
        $instance = $model->newInstance([], true)->setRawAttributes($attributes);
        $keys = $only ?? array_keys($attributes);

        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $attributes)) {
                $out[$key] = $instance->getAttribute($key);
            }
        }

        return $out;
    }
}
