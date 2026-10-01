<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Telegram\Bot\Api;
use Throwable;

/**
 * Everything that touches Telegram file URLs lives here, because those URLs
 * embed the bot token. Nothing built here may leave the backend, and anything
 * logged from a failure is redacted.
 */
class TelegramFiles
{
    public const DISK = 'local';

    public function __construct(private Api $telegram) {}

    /**
     * Download a photo to the private disk. Returns the disk-relative path,
     * or null when it could not be fetched or is not an image.
     */
    public function storePhoto(string $fileId): ?string
    {
        $disk = Storage::disk(self::DISK);
        $path = 'images/'.now()->format('Y/m').'/'.Str::uuid().'.jpg';

        try {
            $this->telegram->downloadFile($fileId, $disk->path($path));

            // Never trust the remote content: it must really be an image.
            if (@getimagesize($disk->path($path)) === false) {
                throw new \RuntimeException('Downloaded file is not an image');
            }
        } catch (Throwable $e) {
            $disk->delete($path);
            Log::warning('Could not store Telegram photo', ['error' => $this->redact($e->getMessage())]);

            return null;
        }

        return $path;
    }

    /**
     * Resolve a file_id to a download stream via getFile. The token-bearing
     * CDN URL exists only inside this method; callers get the body stream.
     *
     * @return array{stream: StreamInterface, size: int|null}|null
     */
    public function open(string $fileId): ?array
    {
        try {
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $filePath = $file->get('file_path');

            if (! is_string($filePath) || ! preg_match('#^[\w\-./]+$#', $filePath) || str_contains($filePath, '..')) {
                return null;
            }

            $url = 'https://api.telegram.org/file/bot'.$this->telegram->getAccessToken().'/'.$filePath;

            $response = Http::withOptions(['stream' => true])
                ->connectTimeout(10)
                ->timeout(120)
                ->get($url);

            if (! $response->ok()) {
                return null;
            }

            $size = $response->header('Content-Length');

            return [
                'stream' => $response->toPsrResponse()->getBody(),
                'size' => is_numeric($size) ? (int) $size : null,
            ];
        } catch (Throwable $e) {
            Log::warning('Could not open Telegram file', ['error' => $this->redact($e->getMessage())]);

            return null;
        }
    }

    private function redact(string $message): string
    {
        $token = (string) $this->telegram->getAccessToken();

        return $token === '' ? $message : str_replace($token, '***', $message);
    }
}
