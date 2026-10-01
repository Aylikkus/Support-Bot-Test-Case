# Telegram Bot SDK reference (installed version)

Derived from the code in `vendor/irazasyed/telegram-bot-sdk/src` and `composer.lock`.
Only APIs present in the installed version are listed. If the package is upgraded, re-verify this file.

## 1. Installed versions

| Item | Value |
|---|---|
| Package | `irazasyed/telegram-bot-sdk` (single package; Laravel integration lives in `src/Laravel`) |
| Locked version | **v3.16.0** (`composer.lock`, ref `b0e7845e`) |
| `Api::VERSION` constant | `'3.12.0'` (stale, ignore it; trust composer.lock) |
| Namespace | `Telegram\Bot\` |
| Requires | PHP >=8.0, guzzlehttp/guzzle ^7.5.1, illuminate/support 9-13, league/event ^2.2\|^3.0 (installed 3.0.3), psr/container, psr/event-dispatcher |
| `composer.json` constraint | `"irazasyed/telegram-bot-sdk": "*"` |

Notes on the environment:
- There is **no** `vendor/telegram-bot-sdk/*` directory and no separate `laravel` package. The path is `vendor/irazasyed/telegram-bot-sdk`.
- The installed package ships **no tests** (only `composer.json`, `LICENSE.md`, `src/`). Patterns below come from source.
- The project has no Telegram code yet and no `config/telegram.php` (see section 3).

## 2. Laravel integration

- Auto-discovered via composer `extra.laravel`:
  - Provider: `Telegram\Bot\Laravel\TelegramServiceProvider` (deferred).
  - Alias: `Telegram` -> `Telegram\Bot\Laravel\Facades\Telegram`.
- Container bindings:
  - `BotsManager::class` (alias `telegram`): **singleton**, built from `config('telegram')`, with the Laravel container attached.
  - `Api::class` (alias `telegram.bot`): **bind** (not singleton) that returns `BotsManager::bot()` for the default bot. Bots themselves are cached inside the manager, so you get the same `Api` instance each time.
- Artisan: `telegram:webhook` (console only), see section 7.
- Config publishing tag: `telegram-config` (`php artisan vendor:publish --tag=telegram-config` -> `config/telegram.php`). The config is also merged automatically, so publishing is optional.

## 3. Configuration (`config/telegram.php`, key `telegram`)

Defaults come from `src/Laravel/config/telegram.php`.

| Key | Default | Meaning |
|---|---|---|
| `bots.<name>.token` | `env('TELEGRAM_BOT_TOKEN', 'YOUR-BOT-TOKEN')` | Bot token. Empty string or `'0'` throws `TelegramSDKException`. |
| `bots.<name>.certificate_path` | `env('TELEGRAM_CERTIFICATE_PATH', 'YOUR-CERTIFICATE-PATH')` | Only used by `telegram:webhook --setup` (ignored while it equals the placeholder). |
| `bots.<name>.webhook_url` | `env('TELEGRAM_WEBHOOK_URL', 'YOUR-BOT-WEBHOOK-URL')` | Used by `telegram:webhook --setup`; must start with `https://`. |
| `bots.<name>.allowed_updates` | `null` | Passed to `setWebhook` by the artisan command when non-empty. |
| `bots.<name>.commands` | `[]` | Command classes / group names / shared command names for this bot. |
| `default` | `'mybot'` | Default bot name. |
| `async_requests` | `env('TELEGRAM_ASYNC_REQUESTS', false)` | Non-blocking requests. |
| `http_client_handler` | `null` | Custom `HttpClientInterface` (default Guzzle). |
| `base_bot_url` | `null` | Custom base URL (default `https://api.telegram.org/bot`). |
| `resolve_command_dependencies` | `true` | Container-resolve command constructors (DI). |
| `commands` | `[HelpCommand::class]` | Global commands, active for all bots. |
| `command_groups` | `[]` | Named groups: class lists, shared command names, or other group names (nestable). |
| `shared_commands` | `[]` | `name => class` registry; only active when referenced by name in a bot or group. |

Rules for this project:
- The token comes from `TELEGRAM_BOT_TOKEN` in the environment only. Never hardcode it. The token in the default config is the placeholder `YOUR-BOT-TOKEN`, so a missing env var does **not** throw; it silently uses the placeholder and requests fail with an API error. Validate the env var explicitly.
- Default bot name is `mybot`. Either keep it or publish the config and rename it.
- The manager resolves `token` in `makeBot()`. An unknown bot name throws `TelegramBotNotFoundException`.
- Because the default `commands` contains `HelpCommand`, an **unknown `/command` falls back to the help command** (see section 8). Remove it from `commands` if that is not wanted.

## 4. Dependency injection

```php
use Telegram\Bot\Api;
use Telegram\Bot\BotsManager;

public function __construct(private Api $telegram) {}          // default bot
public function __construct(private BotsManager $bots) {}      // $this->bots->bot('mybot')
```

- Inject `Api` for a single bot; inject `BotsManager` for named bots (`bot()`, `reconnect()`, `disconnect()`, `hasBot()`, `getBots()`, `getBotConfig()`, `getConfig()`, `getDefaultBotName()`, `setDefaultBot()`).
- `BotsManager::__call` proxies any method to the default bot.
- Command constructors are resolved through the container (`Api::setContainer` is called when `resolve_command_dependencies` is true), so type-hinted services in a command constructor are injected. Commands are built once when the bot is created (`addCommands`), not per update.
- `Api` is `Macroable` (`Api::macro(...)`).
- Wrap `Api` behind an app interface for tests; mock `Api` directly, it is not `final`.

## 5. Facades

`Telegram\Bot\Laravel\Facades\Telegram` (alias `Telegram`) resolves to `BotsManager` (accessor `telegram`), so:

```php
Telegram::sendMessage([...]);          // proxied to the default bot
Telegram::bot('other')->sendMessage([...]);
```

The facade docblock lists the `Api` methods (incomplete; the authoritative list is section 9).
Prefer constructor injection over the facade in application code.

## 6. Commands

Base class: `Telegram\Bot\Commands\Command` (abstract, uses `Answerable`).

```php
use Telegram\Bot\Commands\Command;

class StartCommand extends Command
{
    protected string $name = 'start';
    protected array $aliases = ['begin'];
    protected string $description = 'Start the bot';
    protected string $pattern = '{code}';        // optional: {name} or {name: regex}

    public function handle(): void
    {
        $code = $this->argument('code');
        $this->replyWithMessage(['text' => 'Hello']);
    }
}
```

- Register in `config/telegram.php` (`commands`, `bots.<n>.commands`, `command_groups`, `shared_commands`) or at runtime: `$api->addCommand(StartCommand::class)` / `addCommands([...])` / `removeCommand($name)` / `removeCommands([...])` / `getCommands()`. These are forwarded to `CommandBus` through `Api::__call` (any method matching `^\w+Commands?`).
- Inside a command: `$this->telegram` (`getTelegram()`), `getUpdate()`, `argument($name, $default)`, `getArguments()`, `triggerCommand($name)`.
- `replyWith<X>([...])` magic (from `Answerable`): calls `send<X>` on the API with `chat_id` from `$update->getChat()->id`, e.g. `replyWithMessage`, `replyWithPhoto`, `replyWithChatAction`, `replyWithDocument`. It throws `BadMethodCallException` when the method does not exist or there is no chat.
- Alias conflicts with a command name or another alias throw `TelegramSDKException`.
- Command names are parsed from `bot_command` entities; `/cmd@BotName` is reduced to `cmd`.
- A command class must implement `CommandInterface` (`getName`, `getAliases`, `getDescription`, `getArguments`, `make`).
- `HelpCommand` (name `help`) replies with a list of `/name - description` for all registered commands.
- Commands only see **messages with entities**. Callback queries and plain text need custom handling (sections 8, 11).
- Command menu in the Telegram client: `setMyCommands`, `getMyCommands`, `deleteMyCommands` (the SDK does not sync it automatically).

## 7. Webhook handling

The SDK provides **no route, controller or middleware and no secret-token verification helper**. You write the route.

Methods on `Api`:
- `setWebhook(array $params): bool`. `url` is required and must be a valid **https** URL, otherwise `TelegramSDKException`. `allowed_updates` is JSON-encoded for you. `certificate` (path or `InputFile`) triggers a multipart upload. Other keys (e.g. `secret_token`, `max_connections`, `drop_pending_updates`, `ip_address`) are passed straight through to Telegram.
- `deleteWebhook(): bool` and alias `removeWebhook()`. **Takes no params**, so `drop_pending_updates` is not supported through this method (use `$api->post('deleteWebhook', [...])`).
- `getWebhookInfo(): WebhookInfo`.
- `getWebhookUpdate(bool $shouldDispatchEvents = true, ?RequestInterface $request = null): Update`. Reads `php://input` (or the given PSR-7 request) and `json_decode`s it. `getWebhookUpdates()` is a deprecated alias.
- `commandsHandler(bool $webhook = false, ?RequestInterface $request = null): Update|array`. With `true` it reads the webhook update and runs commands; with `false` it long-polls `getUpdates` (up to 100 updates, marks them read).

Artisan: `php artisan telegram:webhook {bot?} {--setup} {--remove} {--info} {--all}`. `--setup` reads `webhook_url`, `certificate_path` and `allowed_updates` from config and does **not** send `secret_token`; set the webhook manually with `setWebhook` if you need one.

Laravel-specific gotcha: `getWebhookUpdate()` reads `php://input` by default. In a controller/tests, where the body may already be consumed or faked, build the update from the request instead:

```php
use Telegram\Bot\Objects\Update;

$update = new Update($request->all());                   // no events, no command processing
$api->processCommand($update);                            // run the command bus
// or: $api->commandsHandler(true, $psr7Request);
```

Recommended webhook shape: a POST route excluded from CSRF (`bootstrap/app.php` `validateCsrfTokens(except: [...])`), verify the `X-Telegram-Bot-Api-Secret-Token` header yourself, persist the update, return 200 quickly and do slow work (LLM calls) in a queued job.

## 8. Update handling

`Telegram\Bot\Objects\Update` (a `Collection` subclass; camelCase or snake_case property access via `__get`):
- Update types known to this version: `message`, `edited_message`, `channel_post`, `edited_channel_post`, `inline_query`, `chosen_inline_result`, `callback_query`, `shipping_query`, `pre_checkout_query`, `poll`, `poll_answer`, `my_chat_member`, `chat_member`, `chat_join_request`.
- **Not modelled** (no relation, and `detectType()` ignores them): `message_reaction`, `chat_boost`, `business_*`, etc. They arrive as plain `TelegramObject`/arrays via `$update->get('...')`. Do not rely on `getMessage()`/`getChat()` for them (`getChat()` only special-cases `my_chat_member` and `chat_boost`).
- Helpers: `objectType()`, `detectType()`, `isType($type)`, `getMessage()`, `getChat()`, `hasCommand()`, `getRelatedObject()`, `updateId` (`$update->updateId`).
- `getMessage()` returns a **Collection** (empty collection if there is no message). It does not return null, so use `->has('text')`, `->get('text')` or `$update->message`. For `callback_query` it returns the callback's attached message, or an empty collection when `inline_message_id` is used.
- `$update->message->from->id`, `->chat->id`, `->text`, `->messageId` (snake_case underneath: `message_id`), `->entities` (Collection of `MessageEntity`).
- `Message::objectType()` / `Message::TYPES` detect `text`, `photo`, `document`, `voice`, `video`, `sticker`, `contact`, `location`, etc.
- Objects are **immutable** (`__set` throws `InvalidArgumentException`). A missing property returns `null`.
- Guard against missing fields: `$update->message` may be `null`; and messages carrying only media have no `text`.

Events (League event dispatcher, not Laravel's):
- `getWebhookUpdate()` / `getUpdates()` (with `shouldDispatchEvents = true`) dispatch `UpdateWasReceived`, `UpdateEvent` (name `update`), `UpdateEvent` named after the update type (e.g. `message`), and `UpdateEvent` named `<updateType>.<messageType>` (e.g. `message.text`).
- Subscribe: `$api->on('message.text', fn (UpdateEvent $e) => ...)`, or `useEventDispatcher($emitter)`.
- Listeners are per `Api` instance and per process; register them in a service provider `boot()`.

Command dispatch behaviour (`CommandBus::execute`):
- Lookup order: command by name -> alias -> `help` command -> otherwise `false`. So with `HelpCommand` registered, an unknown `/foo` shows help.
- Only `bot_command` entities trigger it. Multiple commands in one message each run.

## 9. Available API methods (all take `array $params` unless noted)

Return types are the SDK's. Methods return wrapped objects or `bool`; failures throw `TelegramResponseException` (extends `TelegramSDKException`).

**Messages** (`Methods/Message.php`): `sendMessage` (Message), `forwardMessage`, `copyMessage`, `sendPhoto`, `sendAudio`, `sendDocument`, `sendVideo`, `sendAnimation`, `sendVoice`, `sendVideoNote`, `sendMediaGroup`, `sendVenue`, `sendContact`, `sendPoll`, `sendDice` (all Message), `sendChatAction` (bool), `setMessageReaction` (bool).
**Editing / deleting** (`EditMessage.php`): `editMessageText`, `editMessageCaption`, `editMessageMedia`, `editMessageReplyMarkup`, `stopPoll` (Poll), `deleteMessage`, `deleteMessages`.
**Location**: `sendLocation`, `editMessageLiveLocation`, `stopMessageLiveLocation`.
**Queries** (`Query.php`): `answerCallbackQuery` (always returns `true`), `answerInlineQuery` (bool).
**Get** (`Get.php`): `getMe(): User` (no params), `getUserProfilePhotos`, `getFile` (File).
**Updates / webhook** (`Update.php`): `getUpdates(array $params = [], bool $shouldDispatchEvents = true): Update[]`, `setWebhook`, `deleteWebhook()`, `removeWebhook()`, `getWebhookInfo()`, `getWebhookUpdate(...)`, `getWebhookUpdates(...)` (deprecated).
**Bot commands** (`Commands.php`): `setMyCommands`, `getMyCommands($params = [])`, `deleteMyCommands($params = [])`.
**Chat management** (`Chat.php`): `banChatMember`, `kickChatMember` (deprecated), `unbanChatMember`, `restrictChatMember`, `promoteChatMember`, `setChatAdministratorCustomTitle`, `setChatMemberTag`, `banChatSenderChat`, `unbanChatSenderChat`, `setChatPermissions`, `exportChatInviteLink`, `createChatInviteLink`, `editChatInviteLink`, `revokeChatInviteLink`, `approveChatJoinRequest`, `declineChatJoinRequest`, `setChatPhoto`, `deleteChatPhoto`, `setChatTitle`, `setChatDescription`, `pinChatMessage`, `unpinChatMessage`, `unpinAllChatMessages`, `leaveChat`, `getChat`, `getChatAdministrators`, `getChatMemberCount`, `getChatMember`, `setChatStickerSet`, `deleteChatStickerSet`.
**Forum topics** (`Forum.php`): `createForumTopic`, `editForumTopic`, `closeForumTopic`, `reopenForumTopic`, `deleteForumTopic`.
**Stickers**: `sendSticker`, `getStickerSet`, `uploadStickerFile`, `createNewStickerSet`, `addStickerToSet`, `setStickerPositionInSet`, `deleteStickerFromSet`, `setStickerSetThumb`.
**Games**: `sendGame`, `setGameScore`, `getGameHighScores`.
**Payments**: `sendInvoice`, `answerShippingQuery`, `answerPreCheckoutQuery`, `createInvoiceLink`.
**Passport**: `setPassportDataErrors`.
**HTTP/util** (`Traits/Http.php`): `post($endpoint, $params, $fileUpload = false)` (public, generic call to *any* Bot API method that has no dedicated wrapper, returns `TelegramResponse`), `downloadFile`, `getLastResponse`, `getClient`, `get/setAccessToken`, `setAsyncRequest`, `isAsyncRequest`, `setHttpClientHandler`, `setBaseBotUrl`, `get/setTimeOut` (default 60s), `get/setConnectTimeOut` (default 10s).

Anything else in the Bot API (e.g. `sendMessageDraft`, `answerWebAppQuery`, `setChatMenuButton`, `getMyName`, `setMyDescription`, `getForumTopicIconStickers`, business methods) has **no wrapper**. Call it with `$api->post('methodName', $params)` and read `->getResult()`; the result is raw, not wrapped in SDK objects.

Parameters: pass Bot API field names as-is (`chat_id`, `text`, `parse_mode`, `reply_markup`, `reply_to_message_id`...). The SDK does not validate them and does not know newer fields (e.g. `reply_parameters`, `link_preview_options`); they still pass through untouched because the array is forwarded.

## 10. Keyboards

`Telegram\Bot\Keyboard\Keyboard` (a `Collection`; its `__toString` returns JSON), `Button` (Collection), `Base`. `reply_markup` is converted with a `(string)` cast (`replyMarkupToString`), which works for a `Keyboard` object or a pre-encoded JSON string. A raw nested PHP array is **not** JSON-encoded (it becomes `"Array"`), so use a `Keyboard` object or `json_encode` it yourself.

```php
use Telegram\Bot\Keyboard\Keyboard;

$inline = Keyboard::make()->inline()
    ->row([
        Keyboard::inlineButton(['text' => 'Yes', 'callback_data' => 'confirm:1']),
        Keyboard::inlineButton(['text' => 'Site', 'url' => 'https://example.com']),
    ]);

$reply = Keyboard::make()
    ->setResizeKeyboard(true)->setOneTimeKeyboard(true)
    ->row([Keyboard::button(['text' => 'Contact', 'request_contact' => true]), 'Plain text']);

$api->sendMessage(['chat_id' => $id, 'text' => 'Choose', 'reply_markup' => $inline]);
```

- `Keyboard::make()`, `->inline()`, `->row(array $buttons)`, `->isInlineKeyboard()`.
- `Keyboard::button()` / `inlineButton()` accept a string (plain button) or array (returns `Button`).
- Magic `setXxx($v)` setters on `Base` (`setResizeKeyboard`, `setOneTimeKeyboard`, `setSelective`, `setInputFieldPlaceholder`, `setIsPersistent` -> snake_case keys). Any `set*` name is accepted without validation.
- `Keyboard::remove()` (`remove_keyboard`), `Keyboard::forceReply()` (`force_reply`); both are `selective = false` by default.
- Button contents are plain arrays; any Bot API button field (`callback_data`, `url`, `web_app`, `switch_inline_query`...) can be set. `callback_data` is limited to 64 bytes by Telegram, not checked by the SDK.
- Methods `Keyboard::inlineButton`, `Keyboard::button`, `Keyboard::remove`, `Keyboard::forceReply` are the only static builders. There is no `Keyboard::inlineKeyboard()` / `Keyboard::keyboard()`.

## 11. Callback queries

- Update key: `callback_query`, object `CallbackQuery` with relations `from` (User) and `message` (Message). Fields: `id`, `data`, `from`, `message`, `inline_message_id`, `chat_instance`, `game_short_name`.
- **No command/callback router exists.** The command bus ignores callback queries, so branch on `$update->isType('callback_query')` / `$update->callbackQuery`.
- Always acknowledge: `$api->answerCallbackQuery(['callback_query_id' => $update->callbackQuery->id, 'text' => '...', 'show_alert' => false])`.
- Update the message: `editMessageText` / `editMessageReplyMarkup` with `chat_id` + `message_id` (or `inline_message_id`).
- Treat `data` as untrusted user input: validate against an allow-list, and never trust ids in it without checking ownership.

```php
if ($update->isType('callback_query')) {
    $cb = $update->callbackQuery;
    [$action, $id] = explode(':', (string) $cb->data) + [null, null];
    $api->answerCallbackQuery(['callback_query_id' => $cb->id]);
}
```

## 12. Media and files

- Send: `sendPhoto`, `sendAudio`, `sendDocument`, `sendVideo`, `sendAnimation`, `sendVoice`, `sendVideoNote`, `sendMediaGroup`, `sendSticker`. Field value can be a `file_id` string, an https URL or an `InputFile`. `sendPhoto` posts URLs directly; other methods go through `uploadFile`, which treats a string matching `^[\w\-]{20,}$` as a `file_id` (plain post) and requires an `InputFile` for anything else (incl. URLs, for those methods).
- `Telegram\Bot\FileUpload\InputFile`: `InputFile::create($pathOrUrlOrStream, ?$filename)`, `InputFile::createFromContents($string, $filename)`, `getFile()`, `getFilename()`, `getContents()`, `setFile()`, `setFilename()`, `setContents()`, `isFileRemote()`.
  A **local path or stream string must be wrapped in `InputFile`**, otherwise `CouldNotUploadInputFile` is thrown. Thumbnail field is `thumb` (older naming).
- `sendMediaGroup` uses `uploadFile(..., 'media')` and expects a `media` param.
- Receive: `Message` has `photo` (array of `PhotoSize`, largest last), `document`, `audio`, `video`, `voice`, `video_note`, `animation`, `sticker`, `contact`, `location`, `venue`, `poll`, `dice`. Every file object has `file_id`, `file_unique_id`, `file_size`.
- Download: `$api->getFile(['file_id' => $id])` returns `File` (`file_path`, `file_size`). `$api->downloadFile($fileOrObjectOrId, $absolutePathOrDir)` streams the file to disk and returns the saved path (directory is created; if the target has no extension the original name or basename is appended). It throws `TelegramSDKException` on a non-200 status. Bots are limited by Telegram to downloading 20 MB.
- Reactions: `setMessageReaction` (bool).
- Chat actions: `Telegram\Bot\Actions` constants (`TYPING`, `UPLOAD_PHOTO`, `RECORD_VOICE`, `UPLOAD_DOCUMENT`, ...); `RECORD_AUDIO`/`UPLOAD_AUDIO` are deprecated aliases.

## 13. Common patterns

```php
// Send text (HTML). Escape user-provided text with e().
$api->sendMessage(['chat_id' => $chatId, 'text' => e($text), 'parse_mode' => 'HTML']);

// Read incoming text safely
$message = $update->getMessage();               // Collection, never null
$text = (string) $message->get('text', '');
$chatId = $update->getChat()->get('id');         // getChat() returns a Collection
$userId = $message->get('from')?->id;            // get() returns User object; may be null

// Typing indicator
$api->sendChatAction(['chat_id' => $chatId, 'action' => \Telegram\Bot\Actions::TYPING]);

// Errors
try { $api->sendMessage([...]); }
catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
    $e->getHttpStatusCode(); $e->getErrorType(); $e->getResponseData();   // e.g. 403 bot blocked
}
```

- Exceptions: `TelegramSDKException` (base) <- `TelegramResponseException` (API error), `TelegramBotNotFoundException`, `CouldNotUploadInputFile`, `TelegramOtherException`, `TelegramEmojiMapFileNotFoundException`.
- `getLastResponse()` gives the last `TelegramResponse`.
- Telegram message limit is 4096 chars; the SDK does not split long text, so chunk it yourself.
- The SDK does not throttle or retry; handle 429 (`retry_after` in response data) in your job layer.
- Test by mocking `Api` (or by binding a fake `HttpClientInterface` via `telegram.http_client_handler`), never calling the real Telegram API. Faked webhook bodies: build `new Update([...])`.
- Never log the bot token; the request URL contains it.

## 14. Version-specific differences and traps (v3.16.0)

1. `Api::VERSION` reads 3.12.0 but the package is 3.16.0.
2. No secret-token support in `telegram:webhook`; `deleteWebhook()` has no params; `getWebhookInfo()` is `WebhookInfo`.
3. `getWebhookUpdate()` reads `php://input` unless a PSR-7 `RequestInterface` is passed (Laravel's `Illuminate\Http\Request` is **not** PSR-7). Prefer `new Update($request->all())` in controllers.
4. `Update::getMessage()` / `getChat()` return Collections (possibly empty), not null. Newer update types (`message_reaction`, business, boosts other than the `getChat` special case) have no typed support.
5. Unknown commands fall through to `help` (if registered).
6. `answerCallbackQuery` returns `true` regardless of the API result body (errors still throw).
7. `reply_markup` is only serialised when it can be cast to a string, so use `Keyboard` objects or pre-encode JSON.
8. The Bot API wrappers follow an older Bot API surface (`reply_to_message_id`, `thumb`, `kickChatMember`). Newer fields still pass through as raw params.
9. Deprecated and to be removed in SDK v4: `Api::manager()`, `BotsManager::parseBotCommands()`, `Api::triggerCommand()`, `getWebhookUpdates()`, `kickChatMember()`, `Update::detectType()`, `Message::detectType()`, `Actions::RECORD_AUDIO`/`UPLOAD_AUDIO`, `Singleton` trait, `UpdateWasReceived::getName()`.
10. Event system is League/PSR-14 based, separate from Laravel events. Bridge it yourself if you want Laravel listeners.
11. `TelegramServiceProvider` is deferred and `final`; `BotsManager` is `final`, `Api` is not. Extend with macros, not by subclassing the manager.
12. Async mode (`async_requests`) makes calls non-blocking and returns before the response is known; leave it `false` for anything that needs the result.

## 13. File URLs and the bot token (project rules)

- `getFile` returns only `file_path`. The download URL is `https://api.telegram.org/file/bot<TOKEN>/<file_path>`, so **every file URL contains the bot token**. Never send it to the browser, never redirect to it, never store it.
- `downloadFile` failures raise `TelegramSDKException::fileDownloadFailed($reason, $url)`, and the message **includes that URL**. Redact the token before logging (`TelegramFiles::redact`).
- `getAccessToken()` returns the configured token (used by `TelegramFiles` to build the URL server-side).
- Photos are downloaded at receive time to the private `local` disk (`images/...`) and served by `MediaController::image` to authenticated operators. Documents are not stored: `messages.file_id` / `messages.file_name` hold the Telegram file_id and original name, and `MediaController::file` calls `getFile` on demand and streams the bytes through the backend.
