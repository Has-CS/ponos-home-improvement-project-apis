<?php

namespace App\Services\Attachment;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * File-storage slice for the polymorphic `attachments` table.
 *
 * Two ingest paths, both landing in the same place:
 *
 *  - storeBase64Image()   a base64 / data-URI payload in a JSON body. Suits an
 *                         on-device capture (a signature canvas, a camera shot
 *                         a mobile client has already downscaled).
 *  - storeUploadedImage() a real multipart file upload. Suits a browser file
 *                         picker, and any API client that would rather post a
 *                         file than encode one.
 *
 * Callers accept either on the same field and dispatch on the value's type —
 * see MaterialRequestService::storePhotos().
 *
 * Everything goes to the private `local` disk, never the public one: files are
 * retrievable only through the authenticated, access-checked download route.
 */
class AttachmentService
{
    private const DISK = 'local';

    // 10 MB. Raised from 5 MB when material-request photos were added: a phone
    // camera shot routinely exceeds 5 MB before downscaling, and base64 inflates
    // the payload a further ~33%. This is a backstop, not a budget — clients are
    // expected to downscale (~1600px, JPEG q0.7) before sending.
    //
    // NOTE: PHP's own upload_max_filesize / post_max_size cut in BELOW this and
    // are what a real deployment actually hits first. Public so validation can
    // quote one consistent number.
    public const MAX_BYTES = 10_485_760;

    public const ALLOWED_MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /**
     * Decode + store a base64 image and record it as an attachment.
     *
     * @param string $base64  Raw base64 or a data URI ("data:image/png;base64,....").
     * @param array{
     *   attachable_type:string, attachable_id:int, project_id:?int,
     *   attachment_type:string, directory:string, uploaded_by:?int, captured_at?:mixed
     * } $meta
     */
    public function storeBase64Image(string $base64, array $meta): Attachment
    {
        [$binary, $mime] = $this->decodeImage($base64);

        return $this->put($binary, $mime, $meta);
    }

    /**
     * Store an uploaded file and record it as an attachment.
     *
     * The mime is taken from getMimeType() — which sniffs the file's actual
     * contents — never getClientMimeType(), which is caller-supplied and
     * trivially spoofed.
     *
     * @param array{
     *   attachable_type:string, attachable_id:int, project_id:?int,
     *   attachment_type:string, directory:string, uploaded_by:?int, captured_at?:mixed
     * } $meta
     */
    public function storeUploadedImage(UploadedFile $file, array $meta): Attachment
    {
        // A file that breached PHP's own upload_max_filesize arrives here
        // "invalid" rather than absent, so say something useful about it.
        if (! $file->isValid()) {
            abort(422, 'The image failed to upload. It may exceed the server upload limit.');
        }

        $mime = strtolower((string) $file->getMimeType());

        if (! isset(self::ALLOWED_MIME[$mime])) {
            abort(422, 'Unsupported image type; PNG or JPEG only.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            abort(422, 'The image exceeds the '.$this->limitInMb().' MB limit.');
        }

        $binary = (string) file_get_contents($file->getRealPath());

        if ($binary === '') {
            abort(422, 'The image is empty.');
        }

        return $this->put($binary, $mime, $meta);
    }

    /**
     * Store a PDF this application generated, e.g. the purchase-order document.
     *
     * A third ingest path beside the two image ones, and deliberately NOT
     * validated the way they are: the bytes come from our own renderer, not
     * from a caller, so there is nothing to distrust and no size to police —
     * MAX_BYTES exists to bound what a client can upload.
     *
     * The file name is ours too (PO number), rather than a UUID, so a buyer who
     * downloads several orders can tell them apart.
     *
     * @param  array{
     *   attachable_type:string, attachable_id:int, project_id:?int,
     *   attachment_type:string, directory:string, uploaded_by:?int, captured_at?:mixed
     * }  $meta
     */
    public function storePdf(string $binary, string $fileName, array $meta): Attachment
    {
        if ($binary === '') {
            abort(500, 'The generated document was empty.');
        }

        $path = trim($meta['directory'], '/').'/'.Str::uuid()->toString().'-'.$fileName;

        Storage::disk(self::DISK)->put($path, $binary);

        return Attachment::create([
            'attachable_type' => $meta['attachable_type'],
            'attachable_id' => $meta['attachable_id'],
            'project_id' => $meta['project_id'] ?? null,
            'attachment_type' => $meta['attachment_type'],
            'disk' => self::DISK,
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($binary),
            'uploaded_by' => $meta['uploaded_by'] ?? null,
            'captured_at' => $meta['captured_at'] ?? now(),
        ]);
    }

    /**
     * Write the bytes to the private disk and record the row. Shared by both
     * image ingest paths so they can never drift on disk, naming or metadata.
     *
     * @param array<string,mixed> $meta
     */
    private function put(string $binary, string $mime, array $meta): Attachment
    {
        $fileName = Str::uuid()->toString().'.'.self::ALLOWED_MIME[$mime];
        $path = trim($meta['directory'], '/').'/'.$fileName;

        Storage::disk(self::DISK)->put($path, $binary);

        return Attachment::create([
            'attachable_type' => $meta['attachable_type'],
            'attachable_id' => $meta['attachable_id'],
            'project_id' => $meta['project_id'] ?? null,
            'attachment_type' => $meta['attachment_type'],
            'disk' => self::DISK,
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => $mime,
            'size_bytes' => strlen($binary),
            'uploaded_by' => $meta['uploaded_by'] ?? null,
            'captured_at' => $meta['captured_at'] ?? now(),
        ]);
    }

    /**
     * A stored attachment's bytes as a base64 data URI, for embedding directly
     * into a generated PDF. dompdf cannot fetch the authenticated download
     * route, and — per BrandLogo::dataUri(), which embeds the company logo the
     * same way — a filesystem path handed to dompdf is a standing source of
     * breakage on this build. A data URI sidesteps both.
     *
     * Returns null rather than throwing when the file is missing or unreadable,
     * so a document render never fails over one absent image.
     */
    public function dataUri(Attachment $attachment): ?string
    {
        if (! $attachment->mime_type || ! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
            return null;
        }

        $bytes = Storage::disk($attachment->disk)->get($attachment->file_path);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:'.$attachment->mime_type.';base64,'.base64_encode($bytes);
    }

    private function limitInMb(): int
    {
        return (int) (self::MAX_BYTES / 1_048_576);
    }

    /**
     * @return array{0:string,1:string} [binary, mime]
     */
    private function decodeImage(string $base64): array
    {
        $mime = 'image/png';

        // Strip and read the mime from a data URI if present.
        if (str_starts_with($base64, 'data:')) {
            if (preg_match('/^data:(?<mime>[a-z\/+-]+);base64,/i', $base64, $m)) {
                $mime = strtolower($m['mime']);
            }
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        // Messages say "image", not "signature image": this path now also
        // ingests material-request photos, so signature-specific wording would
        // be wrong for most callers.
        if (! isset(self::ALLOWED_MIME[$mime])) {
            abort(422, 'Unsupported image type; PNG or JPEG only.');
        }

        $binary = base64_decode(strtr($base64, ' ', '+'), true);
        if ($binary === false) {
            abort(422, 'The image is not valid base64.');
        }
        if (strlen($binary) === 0) {
            abort(422, 'The image is empty.');
        }
        if (strlen($binary) > self::MAX_BYTES) {
            abort(422, 'The image exceeds the '.$this->limitInMb().' MB limit.');
        }

        return [$binary, $mime];
    }
}
