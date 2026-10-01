# Project instructions

## Stack
- PHP 8.5
- Laravel 13
- irazasyed/telegram-bot-sdk
- PostgreSQL
- Pest
- Docker Compose

Everything should run with simple 'docker compose up'

## LLM rules
- Never trust raw LLM output.
- LLM provider must be isolated.
- Do not put API keys into source code.

## Telegram
- Before implementing or changing any Telegram functionality (bot commands, webhook, keyboards, callbacks, media, API calls), read `docs/telegram-sdk.md`. It documents only what the installed irazasyed/telegram-bot-sdk (v3.16.0) actually provides.
- Use only APIs listed there. If something is not documented, verify it in `vendor/irazasyed/telegram-bot-sdk/src` first, then update the doc.
- If the SDK is upgraded, re-verify and update `docs/telegram-sdk.md`.
- Bot token via `TELEGRAM_BOT_TOKEN` env only.

## Development
- Run tests after backend changes.
- Schema is defined in CLAUDE.md; don't deviate from it without asking.
- Use Laravel built-in Hash facade
- Images should be stored locally with Flysystem package
- When dealing with files you need to use backend proxy for public urls in order to not expose BOT token
- For files and images only authorized operators have access to urls
- user_id column is always Telegram id of message sender

## Database schema

requests
---------
request_id              PK
user_id                 BIGINT
status                  ENUM('open', 'closed')
forwarded_to_operator   BOOLEAN
created_at              DATETIME
forwarded_at            DATETIME NULL
closed_at               DATETIME NULL

messages
---------
message_id              PK
request_id              FK -> requests.request_id
user_id                 BIGINT NULL
telegram_message_id     BIGINT
sender_type             ENUM('user', 'bot', 'operator')
operator_id             FK -> operators.operator_id NULL
message_type            ENUM('text', 'image', 'file')
text                    TEXT NULL
image_path              TEXT NULL
file_id                 TEXT NULL
file_name               TEXT NULL
created_at              DATETIME

operators
---------
operator_id             PK
name                    TEXT UNIQUE
password                TEXT
