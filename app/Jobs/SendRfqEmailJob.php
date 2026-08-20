<?php

namespace App\Jobs;

use App\Mail\Rfq\RfqQuoteRequestMail;
use App\Models\EmailLog;
use App\Models\Rfq;
use App\Services\Rfq\RfqPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendRfqEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $emailLogId,
        public int $rfqId,
    ) {}

    /**
     * Intentionally does not catch Throwable — letting it propagate is what
     * lets Laravel's queue retry/backoff apply. Only failed() below records a
     * terminal failure once retries are exhausted.
     */
    public function handle(RfqPdfService $pdf): void
    {
        $rfq = Rfq::with('vendor')->findOrFail($this->rfqId);

        $document = $pdf->storedDocument($rfq);

        if (! $document || ! Storage::disk($document->disk)->exists($document->file_path)) {
            throw new RuntimeException("No filed document found for RFQ #{$this->rfqId}.");
        }

        $bytes = Storage::disk($document->disk)->get($document->file_path);

        Mail::to($rfq->vendor->email)->send(new RfqQuoteRequestMail(
            rfq: $rfq,
            pdfBytes: $bytes,
            pdfFileName: $pdf->fileName($rfq),
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
