<?php

namespace Tests\Feature\DailyLog;

use App\Models\Attachment;
use App\Models\DailyLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Rbac\RoleAssignmentService;
use Database\Seeders\LookupSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Editing a filed daily log can add and remove photos in the same call.
 *
 * Two rules carry the weight here:
 *   - removals apply BEFORE the max-5 check, so a full log can swap photos;
 *   - removal soft-deletes the row and leaves the file, matching how every
 *     other attachment in this system is retired.
 *
 * The author/same-day edit window governs photos exactly as it governs text.
 */
class DailyLogPhotoEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $foreman;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(LookupSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('Admin');
        $this->foreman = $this->userWithRole('Foreman');
        $this->project = Project::factory()->create();

        $this->staff($this->foreman);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        app(RoleAssignmentService::class)->assignGlobalRole(
            $user,
            Role::where('name', $roleName)->where('guard_name', 'api')->whereNull('project_id')->firstOrFail(),
        );

        return $user;
    }

    private function staff(User $user): void
    {
        $this->actingAs($this->admin, 'api')
            ->postJson("/api/v1/projects/{$this->project->id}/assign-user", ['user_id' => $user->id])
            ->assertStatus(201);
    }

    /** @return array<int,UploadedFile> */
    private function photos(int $count): array
    {
        return array_map(
            fn (int $i) => UploadedFile::fake()->image("site-{$i}.jpg", 800, 600),
            range(1, $count),
        );
    }

    /**
     * File a log with $count photos already on it.
     *
     * $daysAgo exists because only ONE log per person, per project, per day is
     * allowed (StoreDailyLogRequest::withValidator), so a test needing a second
     * log by the same foreman has to date it differently.
     */
    private function logWith(int $count, int $daysAgo = 0): int
    {
        return (int) $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/daily-logs",
            [
                'log_date' => now()->subDays($daysAgo)->toDateString(),
                'work_description' => 'Framed the partition walls.',
                'photos' => $this->photos($count),
            ],
        )->assertStatus(201)->json('data.id');
    }

    /** @param array<string,mixed> $payload */
    private function edit(int $logId, array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->foreman, 'api')
            ->patch("/api/v1/projects/{$this->project->id}/daily-logs/{$logId}", $payload);
    }

    /** @return array<int,int> live photo ids on a log */
    private function photoIds(int $logId): array
    {
        return DailyLog::findOrFail($logId)->photos()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /* ---------------- adding ---------------- */

    public function test_photos_can_be_added_to_an_existing_log(): void
    {
        $id = $this->logWith(1);

        $this->edit($id, ['photos' => $this->photos(2)])
            ->assertOk()
            ->assertJsonCount(3, 'data.photos');

        $this->assertCount(3, $this->photoIds($id));
    }

    public function test_adding_photos_leaves_the_text_fields_alone(): void
    {
        $id = $this->logWith(1);

        $this->edit($id, ['photos' => $this->photos(1)])
            ->assertOk()
            ->assertJsonPath('data.work_description', 'Framed the partition walls.');
    }

    public function test_text_and_photos_can_be_edited_in_one_call(): void
    {
        $id = $this->logWith(1);

        $this->edit($id, [
            'work_description' => 'Revised: also ran conduit.',
            'crew_count' => 8,
            'photos' => $this->photos(1),
        ])
            ->assertOk()
            ->assertJsonPath('data.work_description', 'Revised: also ran conduit.')
            ->assertJsonPath('data.crew_count', 8)
            ->assertJsonCount(2, 'data.photos');
    }

    /* ---------------- removing ---------------- */

    public function test_a_photo_can_be_removed(): void
    {
        $id = $this->logWith(3);
        $doomed = $this->photoIds($id)[0];

        $this->edit($id, ['remove_photo_ids' => [$doomed]])
            ->assertOk()
            ->assertJsonCount(2, 'data.photos');

        $this->assertNotContains($doomed, $this->photoIds($id));
    }

    /**
     * Soft delete: the row leaves every query so the download route stops
     * resolving it, but the bytes stay on disk and remain recoverable.
     */
    public function test_removal_soft_deletes_the_row_and_keeps_the_file(): void
    {
        $id = $this->logWith(1);
        $photo = Attachment::firstOrFail();

        $this->edit($id, ['remove_photo_ids' => [$photo->id]])->assertOk();

        $this->assertSoftDeleted('attachments', ['id' => $photo->id]);
        Storage::disk($photo->disk)->assertExists($photo->file_path);

        // And it is no longer downloadable.
        $this->actingAs($this->foreman, 'api')
            ->get("/api/v1/attachments/{$photo->id}")
            ->assertStatus(404);
    }

    /** One log's edit must never be able to delete another's evidence. */
    public function test_a_photo_belonging_to_another_log_cannot_be_removed(): void
    {
        $mine = $this->logWith(1);
        $theirs = $this->logWith(1, daysAgo: 1);

        $victim = $this->photoIds($theirs)[0];

        $this->edit($mine, ['remove_photo_ids' => [$victim]])->assertStatus(422);

        $this->assertContains($victim, $this->photoIds($theirs));
        $this->assertDatabaseHas('attachments', ['id' => $victim, 'deleted_at' => null]);
    }

    public function test_removing_an_unknown_attachment_id_is_rejected(): void
    {
        $id = $this->logWith(1);

        $this->edit($id, ['remove_photo_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['remove_photo_ids.0']);
    }

    /* ---------------- the max-5 rule across an edit ---------------- */

    public function test_a_full_log_can_swap_photos_in_one_call(): void
    {
        // The point of applying removals before the cap check.
        $id = $this->logWith(5);
        $drop = array_slice($this->photoIds($id), 0, 2);

        $this->edit($id, [
            'remove_photo_ids' => $drop,
            'photos' => $this->photos(2),
        ])
            ->assertOk()
            ->assertJsonCount(5, 'data.photos');

        foreach ($drop as $gone) {
            $this->assertNotContains($gone, $this->photoIds($id));
        }
    }

    public function test_an_edit_that_would_exceed_five_is_rejected(): void
    {
        $id = $this->logWith(4);

        $this->edit($id, ['photos' => $this->photos(2)])->assertStatus(422);

        // Nothing applied — still the original four.
        $this->assertCount(4, $this->photoIds($id));
    }

    public function test_a_rejected_edit_rolls_back_the_removals_too(): void
    {
        // Removals happen before the cap check, so they must be undone when it
        // fails — otherwise a bad edit silently destroys photos.
        $id = $this->logWith(5);
        $drop = [$this->photoIds($id)[0]];

        $this->edit($id, [
            'remove_photo_ids' => $drop,
            'photos' => $this->photos(3),   // 5 - 1 + 3 = 7 -> over
        ])->assertStatus(422);

        $this->assertCount(5, $this->photoIds($id));
        $this->assertDatabaseHas('attachments', ['id' => $drop[0], 'deleted_at' => null]);
    }

    public function test_more_than_five_photos_in_one_batch_is_rejected(): void
    {
        $id = $this->logWith(0);

        $this->edit($id, ['photos' => $this->photos(6)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);
    }

    /* ---------------- validation and access ---------------- */

    public function test_an_invalid_photo_is_rejected_and_changes_nothing(): void
    {
        $id = $this->logWith(1);

        $this->edit($id, [
            'work_description' => 'Should not persist.',
            'photos' => [UploadedFile::fake()->create('notes.txt', 20, 'text/plain')],
        ])->assertStatus(422);

        $log = DailyLog::findOrFail($id);
        $this->assertSame('Framed the partition walls.', $log->work_description);
        $this->assertCount(1, $this->photoIds($id));
    }

    public function test_a_non_author_cannot_edit_photos(): void
    {
        $id = $this->logWith(1);

        $other = $this->userWithRole('Foreman');
        $this->staff($other);

        $this->edit($id, ['photos' => $this->photos(1)], $other)->assertStatus(403);

        $this->assertCount(1, $this->photoIds($id));
    }

    public function test_photos_cannot_be_edited_outside_the_same_day_window(): void
    {
        $id = $this->logWith(1);

        // Age the log past the author's edit window.
        DailyLog::withoutTimestamps(
            fn () => DailyLog::findOrFail($id)->forceFill(['created_at' => now()->subDays(2)])->save()
        );

        $this->edit($id, ['photos' => $this->photos(1)])->assertStatus(403);

        $this->assertCount(1, $this->photoIds($id));
    }

    /* ---------------- the multipart route clients must use ---------------- */

    /**
     * PHP does not parse multipart bodies on a real PATCH, so a client sending
     * files has to POST with `_method=PATCH`. This proves that spoofed route
     * reaches the same handler and applies the same rules.
     */
    public function test_method_spoofed_post_reaches_the_update_handler(): void
    {
        $id = $this->logWith(1);

        $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/daily-logs/{$id}",
            [
                '_method' => 'PATCH',
                'work_description' => 'Sent as a spoofed PATCH.',
                'photos' => $this->photos(1),
            ],
        )
            ->assertOk()
            ->assertJsonPath('data.work_description', 'Sent as a spoofed PATCH.')
            ->assertJsonCount(2, 'data.photos');
    }
}
