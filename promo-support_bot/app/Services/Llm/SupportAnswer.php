<?php

namespace App\Services\Llm;

use App\Enums\AnswerStatus;

/**
 * A validated model answer. Build it only through parse(), which never trusts the raw output.
 */
final readonly class SupportAnswer
{
    /** Telegram's message length limit. */
    public const MAX_LENGTH = 4096;

    public function __construct(public AnswerStatus $status, public ?string $message) {}

    public static function error(): self
    {
        return new self(AnswerStatus::Error, null);
    }

    /**
     * Anything malformed collapses to an error answer, which hands the request to a human.
     */
    public static function parse(string $raw): self
    {
        $json = trim($raw);

        // Tolerate a markdown fence the prompt forbids but models sometimes add.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $m) === 1) {
            $json = $m[1];
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! is_string($data['status'] ?? null)) {
            return self::error();
        }

        $status = AnswerStatus::tryFrom($data['status']);

        if ($status === null) {
            return self::error();
        }

        if ($status === AnswerStatus::Error) {
            return self::error();
        }

        $message = $data['msg'] ?? null;

        if (! is_string($message) || trim($message) === '' || mb_strlen($message) > self::MAX_LENGTH) {
            return self::error();
        }

        return new self($status, trim($message));
    }
}
