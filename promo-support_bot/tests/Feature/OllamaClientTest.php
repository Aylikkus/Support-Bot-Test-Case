<?php

use App\Services\Llm\LlmException;
use App\Services\Llm\OllamaClient;
use Illuminate\Support\Facades\Http;

function ollama(?string $key = 'secret'): OllamaClient
{
    return new OllamaClient($key, 'https://ollama.test/', 'm:1', 5);
}

it('posts the system prompt and history to /api/chat with the bearer token', function () {
    Http::fake(['ollama.test/*' => Http::response(['message' => ['role' => 'assistant', 'content' => '{"status":"ok"}']])]);

    $out = ollama()->chat('SYS', [['role' => 'user', 'content' => 'hi']]);

    expect($out)->toBe('{"status":"ok"}');
    Http::assertSent(fn ($r) => $r->url() === 'https://ollama.test/api/chat'
        && $r->hasHeader('Authorization', 'Bearer secret')
        && $r['model'] === 'm:1'
        && $r['stream'] === false
        && $r['messages'] === [['role' => 'system', 'content' => 'SYS'], ['role' => 'user', 'content' => 'hi']]);
});

it('fails with LlmException on HTTP errors, bad bodies and missing key', function () {
    Http::fake(['ollama.test/*' => Http::response('nope', 500)]);
    expect(fn () => ollama()->chat('s', []))->toThrow(LlmException::class);

    Http::fake(['ollama.test/*' => Http::response(['message' => []])]);
    expect(fn () => ollama()->chat('s', []))->toThrow(LlmException::class);

    Http::fake();
    expect(fn () => ollama(null)->chat('s', []))->toThrow(LlmException::class);
    Http::assertNothingSent();
});
