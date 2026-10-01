<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OllamaClient implements LlmClient
{
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $timeout,
    ) {}

    public function chat(string $systemPrompt, array $messages): string
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new LlmException('OLLAMA_API_KEY is not configured.');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/api/chat', [
                    'model' => $this->model,
                    'messages' => [['role' => 'system', 'content' => $systemPrompt], ...$messages],
                    'format' => 'json',
                    'stream' => false,
                ]);
        } catch (ConnectionException $e) {
            throw new LlmException('Ollama is unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new LlmException('Ollama responded with HTTP '.$response->status().'.');
        }

        $content = $response->json('message.content');

        if (! is_string($content)) {
            throw new LlmException('Ollama response has no message content.');
        }

        return $content;
    }
}
