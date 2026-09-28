<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HubException;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Workspace;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to workspace messages, with the desktop's rules (ADR 0012
 * in comitiva): images sniffed from their bytes (PNG, JPEG, GIF, WebP, 5 MB),
 * text as valid UTF-8 without NUL (1 MB). A message's file block keeps the
 * attachment id as its path; the executing desktop downloads it for the run.
 */
class AttachmentController extends Controller
{
    private const IMAGE_BYTES = 5 * 1024 * 1024;

    private const TEXT_BYTES = 1024 * 1024;

    private const TEXT_TYPES = [
        'application/json', 'application/xml', 'application/yaml', 'application/x-yaml', 'application/toml',
        'application/x-sh', 'application/javascript', 'application/typescript', 'application/sql',
    ];

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->membership($request, $workspace);
        $body = $this->body($request, 'AttachmentInput');
        $bytes = base64_decode($body->dataBase64, true);
        if ($bytes === false) {
            throw HubException::invalid('dataBase64 is not base64');
        }
        $name = trim($body->name);
        $declared = strtolower(trim(explode(';', $body->mediaType)[0]));

        $image = self::sniffImage($bytes);
        if ($image !== null) {
            if (strlen($bytes) > self::IMAGE_BYTES) {
                throw new HubException('attachment_too_large', "{$name} is larger than 5 MB", 413);
            }
            $attachment = $this->save($request, $workspace, $name, $image, $bytes);

            return response()->json([
                'type' => 'image',
                'name' => $name,
                'source' => ['kind' => 'file', 'path' => $attachment->id, 'mediaType' => $image],
            ], 201);
        }
        if (str_starts_with($declared, 'image/')) {
            throw new HubException('unsupported_attachment', "{$name} is not a PNG, JPEG, GIF or WebP image", 415);
        }
        $isText = str_starts_with($declared, 'text/') || in_array($declared, self::TEXT_TYPES, true);
        $looksText = $isText || $declared === '' || $declared === 'application/octet-stream';
        if (! $looksText || str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new HubException('unsupported_attachment', "{$name} is not an image or a text file", 415);
        }
        if (strlen($bytes) > self::TEXT_BYTES) {
            throw new HubException('attachment_too_large', "{$name} is larger than 1 MB", 413);
        }
        $mediaType = $isText ? $declared : 'text/plain';
        $attachment = $this->save($request, $workspace, $name, $mediaType, $bytes);

        return response()->json([
            'type' => 'document',
            'name' => $name,
            'mediaType' => $mediaType,
            'source' => ['kind' => 'file', 'path' => $attachment->id, 'mediaType' => $mediaType],
        ], 201);
    }

    public function show(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->membership($request, Workspace::findOrFail($attachment->workspace_id));
        $disk = Storage::disk((string) config('hub.attachments_disk'));

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->media_type,
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function sniffImage(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xff\xd8\xff") => 'image/jpeg',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function save(Request $request, Workspace $workspace, string $name, string $mediaType, string $bytes): Attachment
    {
        $attachment = new Attachment([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $this->user($request)->id,
            'name' => $name,
            'media_type' => $mediaType,
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'path' => '',
            'created_at' => Iso::now(),
        ]);
        $attachment->id = $attachment->newUniqueId();
        $attachment->path = "attachments/{$workspace->id}/{$attachment->id}";
        Storage::disk((string) config('hub.attachments_disk'))->put($attachment->path, $bytes);
        $attachment->save();

        return $attachment;
    }
}
