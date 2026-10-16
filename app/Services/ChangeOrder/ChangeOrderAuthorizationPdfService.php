<?php

namespace App\Services\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Services\Attachment\AttachmentService;
use App\Support\BrandLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the Work Authorization sheet — the on-site record of what a General
 * Contractor's representative signed for, with their signature embedded.
 *
 * Deliberately a SEPARATE service and template from ChangeOrderPdfService, not a
 * mode of it. The two documents make different claims: this one says "this work
 * was authorised on site at this moment by this person", the change order says
 * "this is what it costs, please approve the amount". Folding them together would
 * mean one template deciding, per branch, whether it is allowed to print money —
 * and getting that branch wrong prints a price the signer never agreed to.
 *
 * ---- what makes this document trustworthy ----
 *
 * It reads the signed_* snapshot on the change order, frozen by
 * ChangeOrderService::createEmergency() in the same transaction that recorded the
 * signature. The PM may edit the change order freely afterwards; none of it
 * reaches this sheet.
 *
 * ---- filed ONCE, and never re-filed ----
 *
 * Unlike the change-order document — which ChangeOrderPdfService re-files at
 * every step that changes what it should say — this is filed exactly once, at
 * capture, and is never regenerated. There is nothing for a later step to change:
 * its every value was fixed the moment the signature was taken.
 *
 * It is filed under attachment_type `authorization`, which matters more than it
 * looks. ChangeOrderPdfService::storeFor() soft-deletes every `document`-typed
 * attachment on the change order before filing a new one, so filing this as a
 * `document` would mean the first prepare or send destroyed the evidence. See
 * migration 2026_09_26_000005.
 */
class ChangeOrderAuthorizationPdfService
{
    private const VIEW = 'pdf.change-order-authorization';

    /**
     * Relations the template touches. Loaded in one place so a template edit
     * cannot silently introduce an N+1.
     *
     * No `status`, no `approvals`, no terms: this sheet reports a moment, not a
     * workflow, and prints no contractual text of its own.
     */
    private const WITH = [
        'costCode',
        'urgency',
        'originator',
        'project',
        'generalContractor',
        'signatures.capturedBy',
        'signatures.signatureAttachment',
    ];

    public function __construct(private readonly AttachmentService $attachments) {}

    /** Raw PDF bytes for this change order's work authorization. */
    public function render(ChangeOrder $co): string
    {
        $co->loadMissing(self::WITH);

        // currentSignature() rather than signatures->first(): the model is the
        // single place that decides which capture authorises a change order, and
        // it excludes voided ones. A voided authorization must never render as
        // though it still authorised anything.
        $signature = $co->currentSignature();

        return (string) Pdf::loadView(self::VIEW, [
            'co' => $co,
            'signature' => $signature,
            'company' => config('company'),
            'logoSrc' => BrandLogo::dataUri(),
            // Resolved here, not in the template, because embedding needs the
            // same disk-read + base64 step BrandLogo uses — dompdf cannot fetch
            // the authenticated download route.
            'signatureSrc' => $signature?->signatureAttachment
                ? $this->attachments->dataUri($signature->signatureAttachment)
                : null,
        ])->setPaper('a4')->output();
    }

    public function fileName(ChangeOrder $co): string
    {
        return str_replace(['/', '\\', ' '], '-', $co->change_order_no).'-work-authorization.pdf';
    }

    /**
     * Render and file the authorization against the change order.
     *
     * Any previously filed authorization is soft-deleted first, so exactly one is
     * current. In the normal course nothing calls this twice — but a re-capture
     * after a void does, and the superseded sheet is retained for audit rather
     * than destroyed, the same way ChangeOrderPdfService supersedes documents.
     */
    public function storeFor(ChangeOrder $co, ?int $userId = null): Attachment
    {
        // Loaded up front rather than relying on render() to do it as a side
        // effect: the metadata below reads currentSignature() off the same model,
        // and depending on argument evaluation order for that is a trap.
        $co->loadMissing(self::WITH);

        $this->existingAuthorizations($co)->each->delete();

        return $this->attachments->storePdf($this->render($co), $this->fileName($co), [
            'attachable_type' => ChangeOrder::class,
            'attachable_id' => $co->id,
            'project_id' => $co->project_id,
            'attachment_type' => 'authorization',
            'directory' => 'change-order-authorizations',
            'uploaded_by' => $userId,
            // Which signature this sheet carries, so a later reader can tell
            // without parsing the PDF — and so a re-capture is distinguishable
            // from a re-render of the same one.
            // Keyed signature_attachment_id, matching what isStale() compares and
            // what the purchase order's sent copies record — the id of the IMAGE,
            // not of the signature row, because it is the image that either
            // embedded or did not.
            'metadata' => ['signature_attachment_id' => $this->embeddableSignatureId($co)],
        ]);
    }

    /** The filed authorization sheet for this CO, if one has been generated. */
    public function storedAuthorization(ChangeOrder $co): ?Attachment
    {
        return $this->existingAuthorizations($co)->first();
    }

    /**
     * The filed authorization to serve or merge, re-filing first when the stored
     * copy cannot be showing the current signature.
     *
     * This is the heal-on-read that used to live on ChangeOrderPdfService, moved
     * here with the signature it protects. The reasoning is unchanged and still
     * applies, only more narrowly: this sheet is filed exactly ONCE, inside
     * createEmergency(), so a copy filed while the signature image could not be
     * read — a storage fault, a half-finished deploy — would otherwise stay wrong
     * forever and every later read would serve those same bytes. Healing on read
     * is what makes the signature appear reliably rather than only on change
     * orders captured after the environment was fixed.
     *
     * Null means nothing has been filed, i.e. every Normal change order and every
     * grandfathered emergency one.
     */
    public function currentAuthorization(ChangeOrder $co, ?int $userId = null): ?Attachment
    {
        $doc = $this->storedAuthorization($co);

        if (! $doc) {
            return null;
        }

        return $this->isStale($co, $doc)
            ? $this->storeFor($co, $userId)
            : $doc;
    }

    /**
     * Whether the filed sheet can still be trusted to show the signature.
     *
     * Deliberately NOT a timestamp comparison: createEmergency() writes the
     * signature BEFORE it files this sheet, so a copy that failed to embed the
     * image is newer than the signature and a date check would call it current.
     */
    private function isStale(ChangeOrder $co, Attachment $doc): bool
    {
        if (! Storage::disk($doc->disk)->exists($doc->file_path)) {
            return true;
        }

        $embeddable = $this->embeddableSignatureId($co);

        // No signature, or one whose image cannot be read at all: re-filing would
        // produce the same bytes, so the stored copy is as good as it gets. This is
        // what stops a permanently missing signature file turning every read into a
        // fresh render.
        if ($embeddable === null) {
            return false;
        }

        return ($doc->metadata['signature_attachment_id'] ?? null) !== $embeddable;
    }

    /**
     * The id of the signature attachment this sheet should carry, or null when
     * there is none or its bytes cannot be read.
     */
    private function embeddableSignatureId(ChangeOrder $co): ?int
    {
        $co->loadMissing(['signatures.signatureAttachment']);

        $signature = $co->currentSignature();

        if (! $signature?->signatureAttachment) {
            return null;
        }

        return $this->attachments->dataUri($signature->signatureAttachment) === null
            ? null
            : (int) $signature->signature_attachment_id;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,Attachment> */
    private function existingAuthorizations(ChangeOrder $co)
    {
        return Attachment::query()
            ->where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $co->id)
            ->where('attachment_type', 'authorization')
            ->orderByDesc('id')
            ->get();
    }
}
