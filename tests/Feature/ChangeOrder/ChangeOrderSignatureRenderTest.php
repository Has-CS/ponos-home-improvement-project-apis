<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderSignature;
use App\Services\ChangeOrder\ChangeOrderAuthorizationPdfService;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The captured signature has to survive the whole way to the PDF BYTES.
 *
 * The other change-order tests assert against a hand-assembled view, which proves
 * the template renders an <img> when handed a data URI but says nothing about
 * whether the service actually resolves one. These go through the real service and
 * read the output, so a regression anywhere along
 * attachment -> disk -> data URI -> dompdf fails here.
 *
 * ---- what moved, and why this file changed ----
 *
 * The signature used to be embedded in the CHANGE ORDER document, and this file
 * used to assert that. It is now embedded in the WORK AUTHORIZATION sheet instead,
 * and the change-order document carries no signature at all.
 *
 * That is not a cosmetic relocation. The on-site signature authorises the WORK,
 * captured before anybody has priced it. The change-order document states a price
 * and asks the General Contractor to accept THAT. Printing a signature taken before
 * the price existed onto the sheet that states the price would present it as
 * acceptance of a figure the signer never saw — the same defect
 * ChangeOrderService::createEmergency() used to commit in the database by recording
 * gc_decision = approved at capture.
 *
 * So every assertion here now targets the authorization sheet, including the
 * heal-on-read behaviour, which followed the signature across.
 */
class ChangeOrderSignatureRenderTest extends ChangeOrderTestCase
{
    /** A 300x80 RGBA PNG - a real signature capture has an alpha channel. */
    private static function signaturePng(): string
    {
        $im = imagecreatetruecolor(300, 80);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $ink = imagecolorallocate($im, 10, 20, 80);

        for ($x = 5; $x < 295; $x++) {
            imagesetpixel($im, $x, (int) (40 + 25 * sin($x / 18)), $ink);
        }

        ob_start();
        imagepng($im);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function emergency(): int
    {
        return (int) $this->actingAs($this->foreman, 'api')
            ->postJson($this->base().'/emergency', [
                'title' => 'Unforeseen conduit conflict at grid C4',
                'scope' => 'Reframing the door opening',
                'location' => 'Level 3, Unit 312',
                'signer_name' => 'Dana Whitfield',
                'signature_image' => self::signaturePng(),
            ])->assertStatus(201)->json('data.id');
    }

    /** Embedded raster images in the PDF. The template's only others are absent in tests. */
    private function imageCount(string $pdf): int
    {
        return substr_count($pdf, '/Subtype /Image') + substr_count($pdf, '/Subtype/Image');
    }

    private function authorization(int $coId): Attachment
    {
        return Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $coId)
            ->where('attachment_type', 'authorization')
            ->firstOrFail();
    }

    private function authorizationBytes(int $coId): string
    {
        $doc = $this->authorization($coId);

        return Storage::disk($doc->disk)->get($doc->file_path);
    }

    /* ---------------- the authorization sheet carries the mark ---------------- */

    public function test_a_live_render_of_the_authorization_embeds_the_captured_signature(): void
    {
        $id = $this->emergency();

        $rendered = app(ChangeOrderAuthorizationPdfService::class)->render(ChangeOrder::findOrFail($id));

        $this->assertStringStartsWith('%PDF-', $rendered);
        $this->assertGreaterThan(
            0,
            $this->imageCount($rendered),
            'The work authorization should carry the captured signature as image data.',
        );
    }

    public function test_the_filed_authorization_carries_the_signature_and_records_it(): void
    {
        $id = $this->emergency();
        $doc = $this->authorization($id);

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        $this->assertSame(
            (int) $sig->signature_attachment_id,
            $doc->metadata['signature_attachment_id'] ?? null,
            'The filed sheet must record which signature it was rendered with.',
        );

        $filed = Storage::disk($doc->disk)->get($doc->file_path);
        $this->assertStringStartsWith('%PDF-', $filed);
        $this->assertGreaterThan(0, $this->imageCount($filed));
    }

    /**
     * The change-order document is the one place the signature must NOT appear.
     * See the class docblock: it would read as acceptance of a price the signer
     * never saw.
     */
    public function test_the_change_order_document_carries_no_signature(): void
    {
        $id = $this->emergency();

        $co = app(ChangeOrderPdfService::class)->render(ChangeOrder::findOrFail($id));
        $normal = app(ChangeOrderPdfService::class)
            ->render(ChangeOrder::findOrFail($this->changeOrderAt('pending_counter_sign')));

        // Compared against a Normal change order rather than an absolute count, so
        // a logo or watermark cannot make this pass or fail by accident. The two
        // must carry the SAME image data now: neither embeds a signature.
        $this->assertSame(
            $this->imageCount($normal),
            $this->imageCount($co),
            'The emergency change-order document must not embed the signature; that belongs on the authorization sheet.',
        );
    }

    /* ---------------- heal-on-read, which moved with the signature ---------------- */

    public function test_an_authorization_filed_without_the_signature_heals_on_download(): void
    {
        $id = $this->emergency();
        $stale = $this->authorization($id);

        // NULL metadata, not an array holding a null: this is the exact state of a
        // sheet filed while the signature image could not be read.
        $stale->forceFill(['metadata' => null])->save();
        $staleId = $stale->id;

        $this->assertNull($this->authorization($id)->metadata, 'Guard: the stale state is a NULL column.');

        $this->actingAs($this->foreman, 'api')
            ->get($this->base().'/'.$id.'/authorization')->assertStatus(200);

        $healed = $this->authorization($id);
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        $this->assertNotSame($staleId, $healed->id, 'A stale authorization should be re-filed.');
        $this->assertSame((int) $sig->signature_attachment_id, $healed->metadata['signature_attachment_id'] ?? null);
        $this->assertGreaterThan(0, $this->imageCount(Storage::disk($healed->disk)->get($healed->file_path)));
    }

    public function test_a_healthy_authorization_is_not_refiled_on_every_download(): void
    {
        $id = $this->emergency();
        $before = $this->authorization($id)->id;

        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/authorization')->assertStatus(200);
        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/authorization')->assertStatus(200);

        $this->assertSame($before, $this->authorization($id)->id, 'A current sheet must be served as-is.');
    }

    public function test_a_missing_signature_file_is_logged_and_does_not_loop(): void
    {
        $id = $this->emergency();
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        // The file vanishes underneath us; the attachment row survives.
        Storage::disk($sig->signatureAttachment->disk)->delete($sig->signatureAttachment->file_path);

        Log::spy();

        $before = $this->authorization($id)->id;
        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/authorization')->assertStatus(200);

        // Re-filing cannot recover bytes that are gone, so it must NOT retry
        // forever — the sheet is served as-is and the cause is logged.
        $this->assertSame($before, $this->authorization($id)->id);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'could not be embedded'));
    }

    /* ---------------- the endpoint ---------------- */

    public function test_the_authorization_endpoint_returns_a_real_pdf(): void
    {
        $id = $this->emergency();

        $response = $this->actingAs($this->foreman, 'api')
            ->get($this->base().'/'.$id.'/authorization')->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame($this->authorizationBytes($id), $response->getContent());
    }

    public function test_a_normal_change_order_has_no_authorization_to_serve(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $this->actingAs($this->pm, 'api')
            ->get($this->base().'/'.$id.'/authorization')->assertStatus(404);
    }

    public function test_the_authorization_endpoint_is_membership_gated(): void
    {
        $id = $this->emergency();

        $this->actingAs($this->globalOnly('Foreman'), 'api')
            ->get($this->base().'/'.$id.'/authorization')->assertStatus(403);
    }

    public function test_a_normal_change_order_without_a_signature_still_renders(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $pdf = app(ChangeOrderPdfService::class)->render(ChangeOrder::findOrFail($id));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
