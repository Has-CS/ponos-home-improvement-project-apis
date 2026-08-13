<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChangeOrderSignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signer_name' => $this->signer_name,
            'signer_title' => $this->signer_title,
            'signer_company' => $this->signer_company,
            'signer_contact' => $this->signer_contact,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'signed_lat' => $this->signed_lat,
            'signed_lng' => $this->signed_lng,
            'location_note' => $this->location_note,
            'device_info' => $this->device_info,
            'captured_by' => $this->whenLoaded('capturedBy', fn () => $this->capturedBy ? [
                'id' => $this->capturedBy->id,
                'name' => trim("{$this->capturedBy->first_name} {$this->capturedBy->last_name}"),
            ] : null),
            // The image itself is served only through the authenticated download route.
            'signature_attachment_id' => $this->signature_attachment_id,
            'signature_download_url' => $this->when(
                (bool) $this->signature_attachment_id,
                fn () => url("/api/v1/attachments/{$this->signature_attachment_id}")
            ),
        ];
    }
}
