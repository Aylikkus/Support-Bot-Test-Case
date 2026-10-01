<?php

namespace App\Services\Telegram;

use App\Enums\AnswerStatus;
use App\Enums\MessageType;
use App\Enums\RequestStatus;
use App\Enums\SenderType;
use App\Events\SupportRequestUpdated;
use App\Models\Message;
use App\Models\SupportRequest;
use App\Services\Llm\SupportAnswer;
use App\Services\Llm\SupportAssistant;
use App\Services\SupportRequestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Update;

class IncomingMessageHandler
{
    public const FORWARDED_TEXT = 'Здравствуйте! Ваше сообщение направлено оператору, подождите пожалуйста. Время работы операторов с 9:00 до 18:00 в будние дни.';

    private TelegramFiles $files;

    private SupportAssistant $assistant;

    private SupportRequestService $requests;

    public function __construct(
        private Api $telegram,
        ?TelegramFiles $files = null,
        ?SupportAssistant $assistant = null,
        ?SupportRequestService $requests = null,
    ) {
        $this->files = $files ?? new TelegramFiles($telegram);
        $this->assistant = $assistant ?? app(SupportAssistant::class);
        $this->requests = $requests ?? new SupportRequestService($telegram);
    }

    /**
     * Store a private text, photo or document message from a user and answer it.
     *
     * Text is answered by the LLM: "ok" closes the request, "partial" keeps it open
     * for clarification, "error" (or any unusable output) forwards it to an operator.
     * Photos and documents cannot be judged by the model, so they are forwarded.
     * A request already forwarded to an operator gets no automatic replies.
     *
     * Database errors propagate so the caller can retry the update;
     * failures to deliver the reply are logged and not retried.
     */
    public function handle(Update $update): void
    {
        // Raw payload: any field may be absent, so never rely on SDK object shapes.
        $payload = $update->toArray()['message'] ?? null;

        if (! is_array($payload) || data_get($payload, 'chat.type') !== 'private') {
            return;
        }

        $userId = data_get($payload, 'from.id');
        $chatId = data_get($payload, 'chat.id');
        $telegramMessageId = data_get($payload, 'message_id');

        if (! is_int($userId) || ! is_int($chatId) || ! is_int($telegramMessageId)) {
            return;
        }

        $content = $this->parseContent($payload);

        if ($content === null) {
            return;
        }

        // Cheap pre-check so a redelivered update does not download the photo again.
        if ($this->alreadyStored($userId, $telegramMessageId)) {
            return;
        }

        $storedPath = $content['type'] === MessageType::Image
            ? $this->files->storePhoto($content['file_id'])
            : null;

        try {
            $request = DB::transaction(function () use ($userId, $telegramMessageId, $content, $storedPath): ?SupportRequest {
                if ($this->alreadyStored($userId, $telegramMessageId)) {
                    return null;
                }

                $request = SupportRequest::query()
                    ->where('user_id', $userId)
                    ->where('status', RequestStatus::Open->value)
                    ->latest('request_id')
                    ->first()
                    ?? SupportRequest::create(['user_id' => $userId]);

                $request->messages()->create([
                    'user_id' => $userId,
                    'telegram_message_id' => $telegramMessageId,
                    'sender_type' => SenderType::User,
                    'message_type' => $content['type'],
                    'text' => $content['text'],
                    'image_path' => $storedPath,
                    'file_id' => $content['type'] === MessageType::File ? $content['file_id'] : null,
                    'file_name' => $content['type'] === MessageType::File ? $content['name'] : null,
                ]);

                return $request;
            });
        } catch (\Throwable $e) {
            // Do not leave an orphaned file behind; the update will be retried.
            if ($storedPath !== null) {
                Storage::disk(TelegramFiles::DISK)->delete($storedPath);
            }

            throw $e;
        }

        if ($request === null) {
            // Lost a race with a concurrent delivery of the same update.
            if ($storedPath !== null) {
                Storage::disk(TelegramFiles::DISK)->delete($storedPath);
            }

            return;
        }

        SupportRequestUpdated::publish($request->request_id);

        if ($request->forwarded_to_operator) {
            return;
        }

        $answer = $content['type'] === MessageType::Text
            ? $this->assistant->answer($request)
            : SupportAnswer::error();

        if ($answer->status === AnswerStatus::Error) {
            // Forward first: the operator must see the request even if the notice cannot be delivered.
            $this->requests->forwardToOperator($request);
            $this->reply($request, $chatId, self::FORWARDED_TEXT);

            return;
        }

        if (! $this->reply($request, $chatId, (string) $answer->message)) {
            return;
        }

        if ($answer->status === AnswerStatus::Ok) {
            $this->requests->close($request);
        }
    }

    /**
     * Send a reply and store it. Delivery failures are logged and reported as false.
     */
    private function reply(SupportRequest $request, int $chatId, string $text): bool
    {
        try {
            $reply = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (TelegramSDKException $e) {
            Log::warning('Could not send Telegram reply', [
                'request_id' => $request->request_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $request->messages()->create([
            'user_id' => null,
            'telegram_message_id' => $reply->messageId,
            'sender_type' => SenderType::Bot,
            'text' => $text,
        ]);

        SupportRequestUpdated::publish($request->request_id);

        return true;
    }

    private function alreadyStored(int $userId, int $telegramMessageId): bool
    {
        return Message::query()
            ->where('sender_type', SenderType::User->value)
            ->where('user_id', $userId)
            ->where('telegram_message_id', $telegramMessageId)
            ->exists();
    }

    /**
     * Normalize the supported message kinds (text, photo, document).
     * Photos arrive as several sizes, the largest last. Captions become the text.
     *
     * @param  array<string, mixed>  $payload
     * @return array{type: MessageType, text: string|null, file_id: string, name: string}|null
     */
    private function parseContent(array $payload): ?array
    {
        $text = data_get($payload, 'text');

        if (is_string($text) && $text !== '') {
            return ['type' => MessageType::Text, 'text' => $text, 'file_id' => '', 'name' => ''];
        }

        $caption = data_get($payload, 'caption');
        $caption = is_string($caption) && $caption !== '' ? mb_substr($caption, 0, 1024) : null;

        $photos = data_get($payload, 'photo');

        if (is_array($photos) && $photos !== []) {
            $fileId = data_get(end($photos), 'file_id');

            return is_string($fileId) && $fileId !== ''
                ? ['type' => MessageType::Image, 'text' => $caption, 'file_id' => $fileId, 'name' => '']
                : null;
        }

        $fileId = data_get($payload, 'document.file_id');

        if (is_string($fileId) && $fileId !== '') {
            return [
                'type' => MessageType::File,
                'text' => $caption,
                'file_id' => $fileId,
                'name' => $this->safeFileName(data_get($payload, 'document.file_name')),
            ];
        }

        return null;
    }

    /** The name is shown to operators and used as the download name, so keep it plain. */
    private function safeFileName(mixed $name): string
    {
        $name = is_string($name) ? $name : '';
        $name = preg_replace('#[\x00-\x1F\x7F/\\\\]#u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 255));

        return $name === '' ? 'file' : $name;
    }
}
