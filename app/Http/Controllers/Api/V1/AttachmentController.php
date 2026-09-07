<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\DailyLog;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Support\Concerns\ScopesProjectAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use ScopesProjectAccess;

    /**
     * GET /api/v1/attachments/{attachment}
     * Streams a stored file to a caller who has access to its project (or Admin).
     * Sensitive artifacts (signatures) live on the private disk and are only
     * reachable through this authenticated, access-checked route.
     */
    public function download(Attachment $attachment): StreamedResponse
    {
        $user = request()->user();

        $allowed = $attachment->project_id
            ? $this->canAccessProject($user, (int) $attachment->project_id)
            : $this->isGlobalAdmin($user);

        // Buyers need the photos a foreman attached to a material request, but
        // purchase-order work is deliberately NOT project-membership-scoped, so
        // canAccessProject() would lock out any Procurement user not staffed onto
        // the project. Widened only for material-request photos — signatures,
        // change-order documents and every other attachment type stay
        // membership-gated.
        if (! $allowed
            && $attachment->attachable_type === MaterialRequest::class
            && $attachment->attachment_type === 'photo') {
            $allowed = $user?->can('manage_purchase_orders') ?? false;
        }

        // Same reasoning for the generated purchase-order document: the buyer
        // who issued the order is usually not staffed onto its project, so
        // membership alone would lock them out of their own PO's PDF.
        if (! $allowed
            && $attachment->attachable_type === PurchaseOrder::class
            && $attachment->attachment_type === 'document') {
            $allowed = $user?->can('manage_purchase_orders') ?? false;
        }

        // Daily Log is the system's one deliberate exception: reading a log
        // requires project membership AND `view_daily_log`, not membership
        // alone. Its photos have to follow the same rule, or a staffed user
        // without that permission could pull a log's evidence while being
        // unable to open the log it belongs to. NARROWS access for daily-log
        // photos only; every other attachment type is untouched.
        if ($allowed && $attachment->attachable_type === DailyLog::class) {
            $allowed = $user?->can('view_daily_log') ?? false;
        }

        abort_unless($allowed, 403, 'You do not have access to this file.');

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->file_path), 404, 'File not found.');

        return Storage::disk($attachment->disk)->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream']
        );
    }
}
