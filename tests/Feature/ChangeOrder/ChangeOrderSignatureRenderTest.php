<?php

namespace Tests\Feature\ChangeOrder;

use App\Models\Attachment;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderSignature;
use App\Services\ChangeOrder\ChangeOrderPdfService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The captured signature has to survive the whole way to the PDF BYTES.
 *
 * The other change-order tests assert against a hand-assembled view, which
 * proves the template renders an <img> when handed a data URI but says nothing
 * about whether ChangeOrderPdfService actually resolves one. These go through
 * the real service and read the output, so a regression anywhere along
 * attachment -> disk -> data URI -> dompdf fails here.
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

    private function document(int $coId): Attachment
    {
        return Attachment::where('attachable_type', ChangeOrder::class)
            ->where('attachable_id', $coId)
            ->where('attachment_type', 'document')
            ->firstOrFail();
    }

    public function test_a_live_render_embeds_the_captured_signature(): void
    {
        $id = $this->emergency();

        $withSig = app(ChangeOrderPdfService::class)->render(ChangeOrder::findOrFail($id));
        $withoutSig = app(ChangeOrderPdfService::class)
            ->render(ChangeOrder::findOrFail($this->changeOrderAt('pending_counter_sign')));

        // Compared against a Normal CO rather than an absolute count, so an added
        // logo or watermark cannot make this pass by accident. Strictly-greater
        // rather than an exact delta: dompdf splits an RGBA image into TWO
        // XObjects (the image plus its alpha mask), and how it chooses to encode
        // a signature is its business, not this test's.
        $this->assertGreaterThan(
            $this->imageCount($withoutSig),
            $this->imageCount($withSig),
            'The emergency document should carry image data the normal one does not: the signature.',
        );
    }

    public function test_the_filed_document_carries_the_signature_and_records_it(): void
    {
        $id = $this->emergency();
        $doc = $this->document($id);

        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        $this->assertSame(
            (int) $sig->signature_attachment_id,
            $doc->metadata['signature_attachment_id'] ?? null,
            'The filed copy must record which signature it was rendered with.',
        );

        $filed = Storage::disk($doc->disk)->get($doc->file_path);
        $this->assertStringStartsWith('%PDF-', $filed);
        $this->assertGreaterThan(0, $this->imageCount($filed));
    }

    public function test_a_document_filed_without_the_signature_heals_on_download(): void
    {
        $id = $this->emergency();
        $stale = $this->document($id);

        // NULL metadata, not an array holding a null: this is the exact state of
        // every document filed before storeFor() started recording the signature,
        // so it is what the existing rows in production actually look like.
        $stale->forceFill(['metadata' => null])->save();
        $staleId = $stale->id;

        $this->assertNull($this->document($id)->metadata, 'Guard: the legacy state is a NULL column.');

        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/pdf')->assertStatus(200);

        $healed = $this->document($id);
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        $this->assertNotSame($staleId, $healed->id, 'A stale document should be re-filed.');
        $this->assertSame((int) $sig->signature_attachment_id, $healed->metadata['signature_attachment_id'] ?? null);
        $this->assertGreaterThan(0, $this->imageCount(Storage::disk($healed->disk)->get($healed->file_path)));
    }

    public function test_a_healthy_document_is_not_refiled_on_every_download(): void
    {
        $id = $this->emergency();
        $before = $this->document($id)->id;

        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/pdf')->assertStatus(200);
        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/pdf')->assertStatus(200);

        $this->assertSame($before, $this->document($id)->id, 'A current document must be served as-is.');
    }

    public function test_a_missing_signature_file_is_logged_and_does_not_loop(): void
    {
        $id = $this->emergency();
        $sig = ChangeOrderSignature::where('change_order_id', $id)->firstOrFail();

        // The file vanishes underneath us; the attachment row survives.
        Storage::disk($sig->signatureAttachment->disk)->delete($sig->signatureAttachment->file_path);

        Log::spy();

        $before = $this->document($id)->id;
        $this->actingAs($this->foreman, 'api')->get($this->base().'/'.$id.'/pdf')->assertStatus(200);

        // Re-filing cannot recover bytes that are gone, so it must NOT retry
        // forever - the document is served as-is and the cause is logged.
        $this->assertSame($before, $this->document($id)->id);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'could not be embedded'));
    }

    public function test_a_normal_change_order_without_a_signature_still_renders(): void
    {
        $id = $this->changeOrderAt('pending_counter_sign');

        $pdf = app(ChangeOrderPdfService::class)->render(ChangeOrder::findOrFail($id));

        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
