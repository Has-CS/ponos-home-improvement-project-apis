<?php

namespace App\Services\Auth;

use App\Jobs\SendPasswordResetEmailJob;
use App\Models\EmailLog;
use App\Models\PasswordResetToken;
use App\Models\UserCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    /**
     * Generate a single-use, time-boxed reset token and email the link.
     *
     * IMPORTANT: this method is intentionally silent about whether the email
     * exists — it always behaves the same to the caller, to prevent account
     * enumeration. The controller returns a generic message regardless.
     */
    public function sendResetLink(string $email): void
    {
        $credential = UserCredential::with('user')->where('email', $email)->first();

        // No such email, or the user record is gone → do nothing, silently.
        if (! $credential || ! $credential->user) {
            return;
        }

        $user = $credential->user;

        DB::transaction(function () use ($user, $credential, $email) {
            // Invalidate any outstanding tokens for this user (single active link).
            PasswordResetToken::where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            // Raw token goes in the email; only its hash is stored.
            $rawToken = Str::random(64);

            PasswordResetToken::create([
                'user_id'    => $user->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addMinutes(config('ponos.password_reset.ttl_minutes')),
            ]);

            $resetUrl = sprintf(
                '%s?token=%s&email=%s',
                rtrim(config('ponos.password_reset.frontend_url'), '/'),
                $rawToken,
                urlencode($email),
            );

            $this->dispatchAndLog($user, $email, $resetUrl);
        });
    }

    /**
     * Validate the token and set the new password.
     *
     * @throws ValidationException
     */
    public function reset(string $email, string $rawToken, string $newPassword): void
    {
        $credential = UserCredential::with('user')->where('email', $email)->first();

        if (! $credential || ! $credential->user) {
            $this->fail();
        }

        $tokenHash = hash('sha256', $rawToken);

        $resetToken = PasswordResetToken::where('user_id', $credential->user->id)
            ->where('token_hash', $tokenHash)
            ->latest('id')
            ->first();

        if (! $resetToken || ! $resetToken->isUsable()) {
            $this->fail('This reset link is invalid or has expired.');
        }

        DB::transaction(function () use ($credential, $resetToken, $newPassword) {
            $credential->forceFill([
                'password'             => $newPassword, // hashed by cast
                'password_changed_at'  => now(),
                'must_change_password' => false,
            ])->save();

            $resetToken->forceFill(['used_at' => now()])->save();

            // Belt-and-suspenders: burn any other live tokens for this user.
            PasswordResetToken::where('user_id', $credential->user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        });
    }

    /**
     * Log + queue the reset-link email. Dispatched afterCommit() so a fast
     * worker can't try to update the EmailLog row before this transaction
     * (which created it) has actually committed.
     */
    private function dispatchAndLog($user, string $email, string $resetUrl): void
    {
        $log = EmailLog::create([
            'recipient_user_id' => $user->id,
            'to_email'          => $email,
            'subject'           => 'Reset your PONOS password',
            'template'          => 'password-reset',
            'mailable_type'     => $user::class,
            'mailable_id'       => $user->id,
            'status'            => 'queued',
        ]);

        SendPasswordResetEmailJob::dispatch(
            $log->id,
            $user->first_name,
            $email,
            $resetUrl,
            config('ponos.password_reset.ttl_minutes'),
        )->afterCommit();
    }

    private function fail(string $message = 'This reset link is invalid or has expired.'): never
    {
        throw ValidationException::withMessages([
            'email' => [$message],
        ]);
    }
}
