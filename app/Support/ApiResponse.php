<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * $reference is the correlation id for an unexpected failure — see the
     * \Throwable handler in bootstrap/app.php. It is emitted ONLY when set, so
     * the deliberate errors raised throughout the app (abort(), validation,
     * 404s) keep the exact three-key envelope clients already parse; a
     * `reference` in a response means "this was a masked 500, quote it".
     */
    public static function error(
        string $message = 'Something went wrong',
        int $status = 400,
        mixed $errors = null,
        ?string $reference = null
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ];

        if ($reference !== null) {
            $payload['reference'] = $reference;
        }

        return response()->json($payload, $status);
    }
}
