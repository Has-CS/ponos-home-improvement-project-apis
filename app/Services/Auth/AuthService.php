<?php

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserCredential;
use App\Models\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Authenticate by email+password (credentials live in user_credentials),
     * enforce the user_status gate, audit the attempt, and issue a JWT.
     *
     * @return array{token: string, user: User}
     * @throws ValidationException
     */
    public function login(string $email, string $password, Request $request): array
    {
        $credential = UserCredential::with('user.status')
            ->where('email', $email)
            ->first();

        // Unknown email OR bad password → same generic error (no user enumeration).
        if (! $credential || ! Hash::check($password, $credential->password)) {
            $this->recordAttempt($credential?->user_id, $email, false, 'invalid_credentials', $request);
            $this->fail();
        }

        $user = $credential->user;

        // R-1: user_statuses is the sole gate. Only 'active' may log in.
        if (! $user || $user->status?->code !== UserStatus::ACTIVE) {
            $this->recordAttempt($user?->id, $email, false, 'inactive_status', $request);
            $this->fail('Your account is not active. Contact an administrator.');
        }

        // Issue the token for this user via the jwt guard.
        $token = Auth::guard('api')->login($user);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->recordAttempt($user->id, $email, true, null, $request);

        return ['token' => $token, 'user' => $user];
    }

    /**
     * Invalidate the current token. With the jwt blacklist enabled (default),
     * this token can no longer be used.
     */
    public function logout(): void
    {
        Auth::guard('api')->logout();
    }

    private function recordAttempt(
        ?int $userId,
        string $email,
        bool $success,
        ?string $reason,
        Request $request
    ): void {
        LoginAttempt::create([
            'user_id'         => $userId,
            'email_attempted' => $email,
            'was_successful'  => $success,
            'failure_reason'  => $reason,
            'ip_address'      => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 512),
        ]);
    }

    private function fail(string $message = 'Invalid credentials.'): never
    {
        throw ValidationException::withMessages([
            'email' => [$message],
        ]);
    }

    /**
     * Return the currently authenticated user, fully loaded for the frontend.
     */
    public function me(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::guard('api')->user();

        return $user->load(['credential', 'status', 'gender']);
    }

    /**
     * Rotate the current token: invalidate the old one, return a fresh token.
     *
     * @return array{token: string, user: \App\Models\User}
     */

    public function refresh(): array
    {
        $token = \Illuminate\Support\Facades\Auth::guard('api')->refresh();

        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::guard('api')->setToken($token)->user();

        return ['token' => $token, 'user' => $user->load(['credential', 'status'])];
    }
}
