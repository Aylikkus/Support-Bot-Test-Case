<?php

namespace App\Services\Llm;

use App\Enums\AnswerStatus;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\SupportRequest;
use Illuminate\Support\Facades\Log;

class SupportAssistant
{
    /** Keeps the prompt bounded for long conversations. */
    private const HISTORY_LIMIT = 20;

    public function __construct(private LlmClient $llm, private PromptBuilder $prompts) {}

    /**
     * Answer the latest user message of a request using the request's text history.
     * Never throws: any provider or parsing failure yields an error answer.
     */
    public function answer(SupportRequest $request): SupportAnswer
    {
        try {
            return SupportAnswer::parse(
                $this->llm->chat($this->prompts->build(), $this->history($request)),
            );
        } catch (LlmException $e) {
            Log::error('LLM request failed', ['request_id' => $request->request_id, 'error' => $e->getMessage()]);

            return SupportAnswer::error();
        }
    }

    /**
     * Operator messages are not part of the dialogue with the model.
     * Open, non-forwarded requests only contain "partial" bot replies, so they are replayed as such.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function history(SupportRequest $request): array
    {
        $messages = $request->messages()
            ->where('message_type', MessageType::Text->value)
            ->whereIn('sender_type', [SenderType::User->value, SenderType::Bot->value])
            ->whereNotNull('text')
            ->latest('message_id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse();

        $history = [];

        foreach ($messages as $message) {
            $history[] = $message->sender_type === SenderType::User
                ? ['role' => 'user', 'content' => $message->text]
                : ['role' => 'assistant', 'content' => json_encode(
                    ['status' => AnswerStatus::Partial->value, 'msg' => $message->text],
                    JSON_UNESCAPED_UNICODE,
                )];
        }

        return $history;
    }
}
