<?php

namespace App\Jobs;

use App\Mail\Auth\PasswordResetMail;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Seconds to wait before each retry attempt. Mailtrap (dev SMTP) has been
     * observed to rate-limit ("550 Too many emails per second"); backoff gives
     * that a chance to clear before the next attempt.
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $emailLogId,
        public string $firstName,
        public string $email,
        public string $resetUrl,
        public int $ttlMinutes,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(new PasswordResetMail(
            resetUrl: $this->resetUrl,
            firstName: $this->firstName,
            ttlMinutes: $this->ttlMinutes,
        ));

        EmailLog::whereKey($this->emailLogId)->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        EmailLog::whereKey($this->emailLogId)->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
        ]);
    }
}
