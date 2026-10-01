<?php

namespace App\Http\Controllers;

use App\Enums\MessageType;
use App\Models\Message;
use App\Services\Telegram\TelegramFiles;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Operator-only access to message attachments (routes sit behind `auth`).
 * Neither the Telegram bot token nor Telegram CDN URLs ever reach the browser.
 */
class MediaController extends Controller
{
    private const SAFE_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Cache-Control' => 'private, max-age=3600',
    ];

    /** Images are stored locally on the private disk and streamed from there. */
    public function image(Message $message): StreamedResponse
    {
        $disk = Storage::disk(TelegramFiles::DISK);
        $path = $message->image_path;

        abort_unless(
            $message->message_type === MessageType::Image
                && is_string($path)
                && str_starts_with($path, 'images/')
                && ! str_contains($path, '..')
                && $disk->exists($path)
                && str_starts_with((string) $disk->mimeType($path), 'image/'),
            404,
        );

        return $disk->response($path, null, self::SAFE_HEADERS);
    }

    /**
     * Files are not stored: resolve the file_id through getFile on demand and
     * stream the bytes through the backend instead of redirecting to the CDN,
     * because the CDN URL contains the bot token.
     */
    public function file(Message $message, TelegramFiles $files): StreamedResponse
    {
        abort_unless($message->message_type === MessageType::File && $message->file_id !== null, 404);

        $opened = $files->open($message->file_id);
        abort_if($opened === null, 502, 'Не удалось получить файл из Telegram.');

        $headers = [
            ...self::SAFE_HEADERS,
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ];

        if ($opened['size'] !== null) {
            $headers['Content-Length'] = (string) $opened['size'];
        }

        $stream = $opened['stream'];

        return response()->streamDownload(function () use ($stream): void {
            while (! $stream->eof()) {
                echo $stream->read(8192);
                flush();
            }
        }, $message->file_name ?? 'file', $headers);
    }
}
