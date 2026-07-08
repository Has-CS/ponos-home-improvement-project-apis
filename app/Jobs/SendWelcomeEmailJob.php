<?php

namespace App\Jobs;

use App\Mail\User\WelcomeMail;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of attempts before the job is considered permanently failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before each retry attempt — gives a transient SMTP
     * rate-limit or network blip time to clear.
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $emailLogId,
        public string $firstName,
        public string $email,
        public string $password,
        public string $loginUrl,
    ) {}

    /**
     * Intentionally does not catch Throwable — letting it propagate is what
     * lets Laravel's queue retry/backoff apply. Only failed() below records
     * a terminal failure once retries are exhausted.
     */
    public function handle(): void
    {
        Mail::to($this->email)->send(new WelcomeMail(
            firstName: $this->firstName,
            email: $this->email,
            password: $this->password,
            loginUrl: $this->loginUrl,
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
