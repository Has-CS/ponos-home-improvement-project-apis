<?php

namespace App\Services\User;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\EmailLog;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Concerns\ResolvesGlobalPermissions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserService
{
    use ResolvesGlobalPermissions;

    /**
     * Eager-load set that makes role/permission rendering N+1-free.
     * roles.permissions covers permissions inherited via roles;
     * permissions covers direct grants.
     */
    private const WITH = ['credential', 'status', 'gender', 'roles', 'permissions', 'roles.permissions'];

    /**
     * Admin-create-user flow (R-2). Creates users + user_credentials in one
     * transaction. The new account is forced to change password on first login.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data, ?int $createdBy = null): User
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // Default new accounts to 'active' unless caller specified a status.
            $statusId = $data['user_status_id']
                ?? UserStatus::where('code', UserStatus::ACTIVE)->value('id');

            $picturePath = null;
            if (! empty($data['picture']) && $data['picture'] instanceof UploadedFile) {
                $picturePath = $data['picture']->store('users/pictures', 'public');
            }

            $user = User::create([
                'first_name'     => $data['first_name'],
                'last_name'      => $data['last_name'],
                'gender_id'      => $data['gender_id'] ?? null,
                'date_of_birth'  => $data['date_of_birth'] ?? null,
                'picture_path'   => $picturePath,
                'user_status_id' => $statusId,
                'created_by'     => $createdBy,
            ]);

            // Admin never sets the password — a strong one is generated and
            // emailed to the user, who must change it on first login (R-2).
            $plainPassword = Str::password(config('ponos.welcome.generated_password_length', 16));

            $user->credential()->create([
                'email'                => $data['email'],
                'password'             => $plainPassword, // hashed by cast
                'must_change_password' => true,              // R-2
                'password_changed_at'  => null,
            ]);

            $this->sendWelcomeEmail($user, $data['email'], $plainPassword);

            if (! empty($data['role_ids'])) {
                $this->withGlobalTeam(fn() => $user->syncRoles(array_map('intval', $data['role_ids'])), $user);
            }

            return $user->load(self::WITH);
        });
    }

    /**
     * Log + queue the new-account welcome email (email + temp password +
     * login link). Dispatched afterCommit() so the queue worker never sees
     * the EmailLog row (or the user/credential rows) before this transaction commits.
     */
    private function sendWelcomeEmail(User $user, string $email, string $plainPassword): void
    {
        $log = EmailLog::create([
            'recipient_user_id' => $user->id,
            'to_email'          => $email,
            'subject'           => 'Welcome to PONOS',
            'template'          => 'welcome',
            'mailable_type'     => $user::class,
            'mailable_id'       => $user->id,
            'status'            => 'queued',
        ]);

        SendWelcomeEmailJob::dispatch(
            $log->id,
            $user->first_name,
            $email,
            $plainPassword,
            config('ponos.welcome.login_url'),
        )->afterCommit();
    }


    /**
     * Paginated list with search/filter/sort. Roles + permissions eager-loaded.
     *
     * @param array<string,mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);
        $sortBy  = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        $query = User::query()->with(self::WITH);

        if (! empty($filters['status_id'])) {
            $query->where('user_status_id', $filters['status_id']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'ilike', "%{$term}%")
                    ->orWhere('last_name', 'ilike', "%{$term}%")
                    ->orWhereHas('credential', fn($c) => $c->where('email', 'ilike', "%{$term}%"));
            });
        }

        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        // Ensure role/permission reads resolve against the GLOBAL team scope.
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(0); // 0 = global sentinel (NOT-NULL pivot columns)
        $paginator->getCollection()->each->unsetRelation('roles')->each->unsetRelation('permissions');
        // relations were eager-loaded above; re-touch to reload under the global team scope
        $paginator->setCollection($paginator->getCollection()->load(['roles', 'permissions', 'roles.permissions']));
        $registrar->setPermissionsTeamId($previous);

        return $paginator;
    }


    /**
     * Load one user with everything needed for the detail shape.
     */
    public function findDetailed(User $user): User
    {
        return $this->withGlobalTeam(fn() => $user->load(self::WITH), $user);
    }

    /**
     * Partial update of profile + optional role/permission sync.
     *
     * @param array<string,mixed> $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // --- profile fields (only those present) ---
            $profile = array_intersect_key($data, array_flip([
                'first_name',
                'last_name',
                'gender_id',
                'date_of_birth',
                'user_status_id',
            ]));

            // --- picture (replace old file, if any, with the new upload) ---
            if (! empty($data['picture']) && $data['picture'] instanceof UploadedFile) {
                if ($user->picture_path) {
                    Storage::disk('public')->delete($user->picture_path);
                }
                $profile['picture_path'] = $data['picture']->store('users/pictures', 'public');
            }

            if (! empty($profile)) {
                $user->fill($profile)->save();
            }

            // --- email (lives in user_credentials) ---
            if (array_key_exists('email', $data)) {
                $user->credential()->updateOrCreate(
                    ['user_id' => $user->id],
                    ['email' => $data['email']],
                );
            }

            // --- roles / permissions, forced to GLOBAL scope ---
            $this->withGlobalTeam(function () use ($user, $data) {
                if (array_key_exists('role_ids', $data)) {
                    $user->syncRoles(array_map('intval', $data['role_ids'])); // [] clears all global roles
                }
                if (array_key_exists('permissions', $data)) {
                    $user->syncPermissions($data['permissions']);
                }
            }, $user);

            return $user->load(self::WITH);
        });
    }

    /**
     * Soft delete. FK ON DELETE is RESTRICT in the schema, so we only ever
     * soft-delete (logical), never hard delete.
     */
    public function delete(User $user): void
    {
        $user->delete(); // SoftDeletes trait → sets deleted_at
    }
}
