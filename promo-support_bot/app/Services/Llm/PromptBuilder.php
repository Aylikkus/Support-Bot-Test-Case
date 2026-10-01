<?php

namespace App\Services\Llm;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

class PromptBuilder
{
    /** The campaign rules are written in Moscow time. */
    private const TIMEZONE = 'Europe/Moscow';

    public function build(?DateTimeInterface $now = null): string
    {
        $date = Date::instance($now ?? now())->setTimezone(self::TIMEZONE);

        // Single-pass replacement: tag-like text inside the rules is left untouched.
        return strtr(File::get(resource_path('prompts/system-prompt.md')), [
            '$DMY_TAG' => $date->locale('ru')->translatedFormat('d F Y'),
            '$RULES_TAG' => trim(File::get(resource_path('prompts/promo-rules.md'))),
        ]);
    }
}
