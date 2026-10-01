<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\SenderType;
use App\Events\SupportRequestUpdated;
use App\Models\Message;
use App\Models\Operator;
use App\Models\SupportRequest;
use Illuminate\Validation\ValidationException;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class SupportRequestService
{
    public function __construct(private Api $telegram) {}

    /**
     * Send an operator's message to the user in Telegram and store it.
     *
     * @throws ValidationException
     */
    public function sendOperatorMessage(SupportRequest $request, Operator $operator, string $text): Message
    {
        $this->ensureOpen($request);

        try {
            // In a private chat the chat id equals the user id.
            $sent = $this->telegram->sendMessage([
                'chat_id' => $request->user_id,
                'text' => $text,
            ]);
        } catch (TelegramSDKException $e) {
            report($e);

            throw ValidationException::withMessages([
                'text' => 'Не удалось отправить сообщение в Telegram.',
            ]);
        }

        $message = $request->messages()->create([
            'user_id' => null,
            'operator_id' => $operator->operator_id,
            'telegram_message_id' => $sent->messageId,
            'sender_type' => SenderType::Operator,
            'text' => $text,
        ]);

        SupportRequestUpdated::publish($request->request_id);

        return $message;
    }

    public function forwardToOperator(SupportRequest $request): void
    {
        if ($request->forwarded_to_operator) {
            return;
        }

        $request->update([
            'forwarded_to_operator' => true,
            'forwarded_at' => now(),
        ]);

        SupportRequestUpdated::publish($request->request_id);
    }

    public function close(SupportRequest $request): void
    {
        if ($request->status === RequestStatus::Closed) {
            return;
        }

        $request->update([
            'status' => RequestStatus::Closed,
            'closed_at' => now(),
        ]);

        SupportRequestUpdated::publish($request->request_id);
    }

    /**
     * @throws ValidationException
     */
    private function ensureOpen(SupportRequest $request): void
    {
        if ($request->status !== RequestStatus::Open) {
            throw ValidationException::withMessages([
                'text' => 'Обращение уже закрыто.',
            ]);
        }
    }
}
