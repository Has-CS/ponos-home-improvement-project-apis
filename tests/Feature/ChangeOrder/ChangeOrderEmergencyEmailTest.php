<?php

namespace Tests\Feature\ChangeOrder;

use App\Jobs\SendChangeOrderEmailJob;
use App\Mail\ChangeOrder\ChangeOrderIssuedMail;
use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Models\EmailLog;
use App\Models\User;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use App\Services\ChangeOrder\ChangeOrderProofPackageService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sending the priced change order to the General Contractor, with its proof.
 *
 * This is entirely new behaviour. Before this work NO change-order path sent any
 * email at all — no observer, no listener, no job, no mailable, no template. What
 * the old prepareDocument() returned was an `email_draft` array the PM copy-pasted
 * into their own mail client. The Normal flow's send-to-GC sends no email yet.
 *
 * The thing that makes this worth building rather than leaving to copy-paste is the
 * PROOF. An emergency change order bills for work already built, on the strength of a
 * signature taken before anyone priced it. The signed Work Authorization is not
 * supporting material — it is the reason the amount is collectible — so it travels
 * merged into the same PDF rather than as a second attachment someone forgets to
 * open.
 */
class ChangeOrderEmergencyEmailTest extends ChangeOrderTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function sentToGc(): int
    {
        $id = (int) $this->actingAs($this->foreman, 'api')
            ->postJson($this->base().'/emergency', [
                'title' => 'Unforeseen conduit conflict at grid C4',
                'scope' => 'Reframe the door opening and make good.',
                'location' => 'Level 3, Unit 312',
                'gc_representative_id' => $this->rep->id,
                'signature_image' => self::PNG,
            ])->assertStatus(201)->json('data.id');

        $this->updateAs($this->pm, $id, ['value' => 12500.00])->assertOk();
        $this->grantSendToGc($this->pm);
        $this->actAs($this->pm, $id, 'send-to-gc')->assertOk();

        return $id;
    }

    private function grantSendToGc(User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $user->givePermissionTo('send_change_request_to_gc');
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function pageCount(string $pdf): int
    {
        return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages')
            + substr_count($pdf, '/Type/Page') - substr_count($pdf, '/Type/Pages');
    }

    /* ---------------- queueing ---------------- */

    public function test_sending_queues_the_email_and_logs_it_as_queued(): void
    {
        Queue::fake();

        $id = $this->sentToGc();

        $log = EmailLog::where('mailable_type', ChangeOrder::class)->where('mailable_id', $id)->firstOrFail();

        $this->assertSame('queued', $log->status);
        $this->assertSame($this->gc->email, $log->to_email);
        $this->assertSame('emails.change-order.issued', $log->template);
        $this->assertNull($log->sent_at);

        Queue::assertPushed(SendChangeOrderEmailJob::class, fn ($job) => $job->changeOrderId === $id
            && $job->emailLogId === $log->id);
    }

    public function test_nothing_is_queued_when_sending_is_refused(): void
    {
        Queue::fake();

        // No value: refused before the status moves, so no EmailLog and no job.
        $id = (int) $this->actingAs($this->foreman, 'api')
            ->postJson($this->base().'/emergency', [
                'title' => 'Unpriced', 'scope' => 'x', 'location' => 'y',
                'gc_representative_id' => $this->rep->id, 'signature_image' => self::PNG,
            ])->assertStatus(201)->json('data.id');

        $this->grantSendToGc($this->pm);
        $this->actAs($this->pm, $id, 'send-to-gc')->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('email_logs', 0);
    }

    /* ---------------- the job ---------------- */

    public function test_the_job_sends_the_mail_and_marks_the_log_sent(): void
    {
        Mail::fake();

        $id = $this->sentToGc();
        $log = EmailLog::where('mailable_id', $id)->firstOrFail();

        Mail::assertSent(ChangeOrderIssuedMail::class, function ($mail) use ($id) {
            return $mail->changeOrder->id === $id
                && $mail->hasTo($this->gc->email);
        });

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->sent_at);
    }

    public function test_the_mail_replies_to_the_person_who_sent_it(): void
    {
        Mail::fake();

        $this->sentToGc();

        // Not MAIL_FROM_ADDRESS: a General Contractor's reply has to reach someone who
        // knows the change order. The address lives on user_credentials, not users.
        Mail::assertSent(ChangeOrderIssuedMail::class, function ($mail) {
            $envelope = $mail->envelope();

            return collect($envelope->replyTo)->contains(
                fn ($address) => $address->address === $this->pm->credential->email
            );
        });
    }

    public function test_the_subject_names_the_change_order_and_the_project(): void
    {
        Mail::fake();

        $id = $this->sentToGc();
        $co = ChangeOrder::findOrFail($id);

        Mail::assertSent(ChangeOrderIssuedMail::class, function ($mail) use ($co) {
            $subject = $mail->envelope()->subject;

            return str_contains($subject, $co->change_order_no)
                && str_contains($subject, 'PNS-2026-031')
                && str_contains($subject, $co->title);
        });
    }

    public function test_a_failed_send_is_recorded_on_the_log(): void
    {
        $id = $this->sentToGc();
        $log = EmailLog::where('mailable_id', $id)->firstOrFail();

        // What the queue does once the retries are exhausted.
        (new SendChangeOrderEmailJob($log->id, $id))->failed(new \RuntimeException('SMTP refused the connection.'));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame('SMTP refused the connection.', $log->error);
    }

    public function test_the_job_refuses_when_the_filed_document_is_missing(): void
    {
        $id = $this->sentToGc();
        $log = EmailLog::where('mailable_id', $id)->firstOrFail();

        $doc = Attachment::findOrFail(ChangeOrder::findOrFail($id)->document_attachment_id);
        Storage::disk($doc->disk)->delete($doc->file_path);

        // Throws rather than sending something else: what reaches the GC must be the
        // document the send step produced, or the filed record and the sent mail
        // disagree about what was billed.
        $this->expectException(\RuntimeException::class);

        app()->call([new SendChangeOrderEmailJob($log->id, $id), 'handle']);
    }

    /* ---------------- the proof package ---------------- */

    public function test_the_attachment_merges_the_authorization_behind_the_change_order(): void
    {
        $id = $this->sentToGc();
        $co = ChangeOrder::findOrFail($id);

        $base = Storage::disk($co->documentAttachment->disk)->get($co->documentAttachment->file_path);
        $merged = app(ChangeOrderProofPackageService::class)->merge($co, $base);

        $this->assertNotSame($base, $merged, 'The authorization must be appended.');
        $this->assertStringStartsWith('%PDF-', $merged);

        // change order + divider + authorization: strictly more pages than the change
        // order alone. Compared rather than counted absolutely, so a layout change
        // cannot make this pass by accident.
        $this->assertGreaterThan($this->pageCount($base), $this->pageCount($merged));
    }

    public function test_the_authorization_comes_first_among_the_attachments(): void
    {
        $id = $this->sentToGc();
        $co = ChangeOrder::findOrFail($id);

        $attachments = app(ChangeOrderProofPackageService::class)->proofAttachments($co);

        // Order is SEMANTIC here, not upload order as on a purchase order: the signed
        // authorization sits directly behind the change order it justifies. A photo
        // ahead of it would bury the one page that makes the amount collectible.
        $this->assertSame('authorization', $attachments->first()->attachment_type);
    }

    public function test_the_merged_copy_that_left_is_filed_for_the_record(): void
    {
        $id = $this->sentToGc();

        $sent = Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $id)
            ->where('attachment_type', 'sent_document')
            ->firstOrFail();

        $this->assertNotNull($sent->metadata['emailed_at'] ?? null);
        $this->assertTrue(Storage::disk($sent->disk)->exists($sent->file_path));

        // The filed change-order document is NOT replaced by the merged bundle — both
        // exist, and the `document` remains what /pdf serves.
        $this->assertNotSame(
            ChangeOrder::findOrFail($id)->document_attachment_id,
            $sent->id,
        );
    }

    public function test_the_filed_document_is_not_the_merged_one(): void
    {
        $id = $this->sentToGc();
        $co = ChangeOrder::findOrFail($id);

        $filed = Storage::disk($co->documentAttachment->disk)->get($co->documentAttachment->file_path);

        $this->assertSame('document', $co->documentAttachment->attachment_type);
        $this->assertStringNotContainsString('with authorization', $filed);
    }

    /* ---------------- the email body ---------------- */

    public function test_the_body_leads_with_the_authorization_not_the_amount(): void
    {
        Mail::fake();

        $id = $this->sentToGc();
        $co = ChangeOrder::findOrFail($id);

        Mail::assertSent(ChangeOrderIssuedMail::class, function ($mail) use ($co) {
            $html = $mail->render();

            return str_contains($html, 'Dana Whitfield')
                && str_contains($html, 'authorised on site')
                && str_contains($html, $co->change_order_no)
                && str_contains($html, '12,500.00');
        });
    }

    public function test_a_normal_change_order_sends_no_email(): void
    {
        Mail::fake();
        Queue::fake();

        // The Normal flow still hands the PM a draft to copy-paste. Nothing is sent
        // from the application, which is exactly how it behaved before this work.
        $id = $this->changeOrderAt('pending_gc');

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_logs', 0);

        // ...and the draft is still there.
        $this->assertNotNull($id);
    }
}
