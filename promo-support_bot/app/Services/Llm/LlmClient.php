<?php

namespace App\Services\Llm;

/**
 * Provider-agnostic chat completion. The rest of the app depends only on this interface.
 */
interface LlmClient
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return string Raw, untrusted model output.
     *
     * @throws LlmException
     */
    public function chat(string $systemPrompt, array $messages): string;
}
