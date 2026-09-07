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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A foreman attaches site photos when filing a daily log — 2-3 typical, five at
 * most, enforced server-side.
 *
 * Storage reuses the polymorphic attachments table and the private disk: only
 * the file PATH is written to the database, never the bytes. Photos are counted
 * on the list and served in full only on detail, matching material requests.
 */
class DailyLogPhotoTest extends TestCase
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

    /** @param array<int,mixed> $photos */
    private function submit(array $photos = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/daily-logs",
            [
                'log_date' => now()->toDateString(),
                'work_description' => 'Framed the second-floor partition walls.',
                'photos' => $photos,
            ],
        );
    }

    /** @return array<int,UploadedFile> */
    private function fakePhotos(int $count): array
    {
        return array_map(
            fn (int $i) => UploadedFile::fake()->image("site-{$i}.jpg", 800, 600),
            range(1, $count),
        );
    }

    /* ---------------- happy paths ---------------- */

    #[DataProvider('allowedCounts')]
    public function test_a_log_can_be_filed_with_photos(int $count): void
    {
        $id = (int) $this->submit($this->fakePhotos($count))->assertStatus(201)->json('data.id');

        $photos = Attachment::where('attachable_type', DailyLog::class)
            ->where('attachable_id', $id)
            ->where('attachment_type', 'photo')
            ->get();

        $this->assertCount($count, $photos);

        foreach ($photos as $photo) {
            $this->assertSame($this->project->id, (int) $photo->project_id);
            $this->assertSame($this->foreman->id, (int) $photo->uploaded_by);
            $this->assertSame('image/jpeg', $photo->mime_type);
            $this->assertGreaterThan(0, $photo->size_bytes);

            // The bytes are on the disk; the row only points at them.
            Storage::disk($photo->disk)->assertExists($photo->file_path);
            $this->assertStringStartsWith('daily-log-photos/', $photo->file_path);
        }
    }

    public static function allowedCounts(): array
    {
        return ['one' => [1], 'typical' => [3], 'the maximum' => [5]];
    }

    public function test_photos_are_optional(): void
    {
        $this->submit()->assertStatus(201)->assertJsonPath('data.photos', []);

        $this->assertSame(0, Attachment::where('attachable_type', DailyLog::class)->count());
    }

    public function test_a_base64_photo_is_accepted_on_the_same_field(): void
    {
        // 1x1 PNG — the on-device camera path, alongside multipart uploads.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $id = (int) $this->actingAs($this->foreman, 'api')->postJson(
            "/api/v1/projects/{$this->project->id}/daily-logs",
            [
                'log_date' => now()->toDateString(),
                'work_description' => 'Framed the partition walls.',
                'photos' => [$png],
            ],
        )->assertStatus(201)->json('data.id');

        $photo = Attachment::where('attachable_id', $id)->firstOrFail();
        $this->assertSame('image/png', $photo->mime_type);
        Storage::disk($photo->disk)->assertExists($photo->file_path);
    }

    /* ---------------- validation ---------------- */

    public function test_more_than_five_photos_is_rejected(): void
    {
        $this->submit($this->fakePhotos(6))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);

        $this->assertSame(0, DailyLog::count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->submit([UploadedFile::fake()->create('notes.txt', 20, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos.0']);

        $this->assertSame(0, DailyLog::count());
    }

    public function test_an_oversized_photo_is_rejected(): void
    {
        // 12 MB, past AttachmentService::MAX_BYTES (10 MB).
        $this->submit([UploadedFile::fake()->create('huge.jpg', 12 * 1024, 'image/jpeg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos.0']);

        $this->assertSame(0, DailyLog::count());
    }

    /**
     * A rejected batch must leave nothing behind — not the log, not the photos
     * that were valid. storePhotos() runs inside create()'s transaction.
     */
    public function test_one_bad_photo_in_a_batch_files_no_log_at_all(): void
    {
        $photos = $this->fakePhotos(2);
        $photos[] = UploadedFile::fake()->create('notes.txt', 20, 'text/plain');

        $this->submit($photos)->assertStatus(422);

        $this->assertSame(0, DailyLog::count());
        $this->assertSame(0, Attachment::count());
    }

    /* ---------------- fetch pattern ---------------- */

    public function test_the_list_returns_a_count_and_not_the_photo_rows(): void
    {
        $this->submit($this->fakePhotos(3))->assertStatus(201);

        $item = $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/daily-logs")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(3, $item['photos_count']);
        $this->assertArrayNotHasKey('photos', $item);
    }

    public function test_the_detail_returns_the_photo_urls(): void
    {
        $id = (int) $this->submit($this->fakePhotos(2))->assertStatus(201)->json('data.id');

        $photos = $this->actingAs($this->foreman, 'api')
            ->getJson("/api/v1/projects/{$this->project->id}/daily-logs/{$id}")
            ->assertOk()
            ->json('data.photos');

        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            $this->assertSame('image/jpeg', $photo['mime_type']);
            $this->assertStringContainsString("/api/v1/attachments/{$photo['id']}", $photo['url']);
        }
    }

    /* ---------------- download access ---------------- */

    public function test_a_project_member_with_view_daily_log_can_download_a_photo(): void
    {
        $this->submit($this->fakePhotos(1))->assertStatus(201);
        $photo = Attachment::firstOrFail();

        $this->actingAs($this->foreman, 'api')
            ->get("/api/v1/attachments/{$photo->id}")
            ->assertOk();
    }

    /**
     * Daily Log is the system's one module where reading needs a permission on
     * top of membership. Its photos must not be a way around that.
     */
    public function test_a_member_without_view_daily_log_cannot_download_a_photo(): void
    {
        $this->submit($this->fakePhotos(1))->assertStatus(201);
        $photo = Attachment::firstOrFail();

        // Procurement is staffed but holds neither daily-log permission.
        $procurement = $this->userWithRole('Procurement');
        $this->staff($procurement);

        $this->actingAs($procurement, 'api')
            ->get("/api/v1/attachments/{$photo->id}")
            ->assertStatus(403);
    }

    /* ---------------- no collateral damage ---------------- */

    public function test_the_other_log_fields_are_unaffected(): void
    {
        $this->actingAs($this->foreman, 'api')->post(
            "/api/v1/projects/{$this->project->id}/daily-logs",
            [
                'log_date' => now()->toDateString(),
                'work_description' => 'Framed the partition walls.',
                'weather' => 'Overcast, 14C',
                'crew_count' => 6,
                'photos' => $this->fakePhotos(2),
            ],
        )->assertStatus(201)
            ->assertJsonPath('data.weather', 'Overcast, 14C')
            ->assertJsonPath('data.crew_count', 6)
            ->assertJsonPath('data.has_issue', false)
            ->assertJsonPath('data.logged_by.role', 'Foreman')
            ->assertJsonCount(2, 'data.photos');
    }

    /**
     * Photo counts must be aggregated in the list query, not resolved per row.
     * Warm-up discarded: the first request of a process pays a cold Spatie
     * permission cache and reads artificially high.
     */
    public function test_the_list_endpoint_does_not_n_plus_one_on_photos(): void
    {
        $count = function (int $logs): int {
            DailyLog::query()->forceDelete();
            Attachment::query()->forceDelete();

            for ($i = 0; $i < $logs; $i++) {
                $log = DailyLog::create([
                    'project_id' => $this->project->id,
                    'logged_by' => $this->foreman->id,
                    'log_date' => now()->toDateString(),
                    'work_description' => "Log {$i}",
                    'has_issue' => false,
                    'created_by' => $this->foreman->id,
                ]);

                Attachment::create([
                    'attachable_type' => DailyLog::class,
                    'attachable_id' => $log->id,
                    'project_id' => $this->project->id,
                    'attachment_type' => 'photo',
                    'disk' => 'local',
                    'file_path' => "daily-log-photos/{$log->id}.jpg",
                    'file_name' => "{$log->id}.jpg",
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 1024,
                    'uploaded_by' => $this->foreman->id,
                ]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->foreman, 'api')
                ->getJson("/api/v1/projects/{$this->project->id}/daily-logs")
                ->assertOk();

            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(2); // discarded warm-up

        $withTwo = $count(2);
        $withEight = $count(8);

        $this->assertSame(
            $withTwo,
            $withEight,
            "Query count moved from {$withTwo} (2 logs) to {$withEight} (8 logs) — "
                .'photo counts are being resolved per row instead of aggregated.',
        );
    }
}
