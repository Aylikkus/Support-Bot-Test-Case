<?php

use App\Enums\RequestStatus;
use App\Enums\SenderType;
use App\Events\SupportRequestUpdated;
use App\Models\Message;
use App\Models\SupportRequest;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use App\Services\Llm\PromptBuilder;
use App\Services\Telegram\IncomingMessageHandler;
use Illuminate\Support\Facades\Event;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message as TelegramMessage;
use Telegram\Bot\Objects\Update;

function makeUpdate(array $message = [], int $updateId = 1): Update
{
    return new Update([
        'update_id' => $updateId,
        'message' => array_merge([
            'message_id' => 10,
            'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan'],
            'chat' => ['id' => 555, 'type' => 'private'],
            'date' => 1780000000,
            'text' => 'Не работает промокод',
        ], $message),
    ]);
}

const FORWARDED = 'Здравствуйте! Ваше сообщение направлено оператору, подождите пожалуйста. Время работы операторов с 9:00 до 18:00 в будние дни.';

/** Replaces the LLM provider; returns the calls it receives. */
function fakeLlm(string|Throwable $output): ArrayObject
{
    $calls = new ArrayObject;

    app()->instance(LlmClient::class, new class($output, $calls) implements LlmClient
    {
        public function __construct(private string|Throwable $output, private ArrayObject $calls) {}

        public function chat(string $systemPrompt, array $messages): string
        {
            $this->calls[] = compact('systemPrompt', 'messages');

            if ($this->output instanceof Throwable) {
                throw $this->output;
            }

            return $this->output;
        }
    });

    return $calls;
}

function handlerReplying(string $text, int $replyId = 99): IncomingMessageHandler
{
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('sendMessage')
        ->with(['chat_id' => 555, 'text' => $text])
        ->andReturn(new TelegramMessage(['message_id' => $replyId]));

    return new IncomingMessageHandler($api);
}

function handlerWithReplyId(int $replyId = 99): IncomingMessageHandler
{
    fakeLlm('{"status":"partial","msg":"Опишите вопрос"}');

    return handlerReplying('Опишите вопрос', $replyId);
}

it('stores the user message and the bot reply under a new open request', function () {
    handlerWithReplyId()->handle(makeUpdate());

    $request = SupportRequest::sole();
    expect($request->user_id)->toBe(555)
        ->and($request->status)->toBe(RequestStatus::Open)
        ->and($request->forwarded_to_operator)->toBeFalse();

    $messages = Message::orderBy('message_id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->sender_type)->toBe(SenderType::User)
        ->and($messages[0]->user_id)->toBe(555)
        ->and($messages[0]->telegram_message_id)->toBe(10)
        ->and($messages[0]->text)->toBe('Не работает промокод')
        ->and($messages[1]->sender_type)->toBe(SenderType::Bot)
        ->and($messages[1]->user_id)->toBeNull()
        ->and($messages[1]->telegram_message_id)->toBe(99)
        ->and($messages[1]->text)->toBe('Опишите вопрос');
});

it('appends to the open request of the same user', function () {
    handlerWithReplyId(99)->handle(makeUpdate(['message_id' => 10]));
    handlerWithReplyId(100)->handle(makeUpdate(['message_id' => 11], 2));

    expect(SupportRequest::count())->toBe(1)
        ->and(Message::count())->toBe(4);
});

it('opens a new request when the previous one is closed', function () {
    SupportRequest::create(['user_id' => 555, 'status' => RequestStatus::Closed]);

    handlerWithReplyId()->handle(makeUpdate());

    expect(SupportRequest::count())->toBe(2)
        ->and(SupportRequest::where('status', 'open')->count())->toBe(1);
});

it('ignores an update that was already stored', function () {
    handlerWithReplyId()->handle(makeUpdate());

    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('sendMessage');
    (new IncomingMessageHandler($api))->handle(makeUpdate());

    expect(Message::count())->toBe(2);
});

it('ignores unsupported and non-private messages', function () {
    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('sendMessage');
    $handler = new IncomingMessageHandler($api);
    $calls = fakeLlm('{"status":"ok","msg":"x"}');

    $handler->handle(makeUpdate(['text' => null, 'voice' => ['file_id' => 'x']]));
    $handler->handle(makeUpdate(['chat' => ['id' => -1, 'type' => 'group']]));
    $handler->handle(new Update(['update_id' => 5, 'edited_message' => ['message_id' => 1]]));

    expect(Message::count())->toBe(0)->and(SupportRequest::count())->toBe(0)->and($calls)->toHaveCount(0);
});

it('keeps the stored message when the reply cannot be delivered', function () {
    fakeLlm('{"status":"ok","msg":"Ответ"}');
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('sendMessage')->andThrow(new TelegramSDKException('blocked'));

    (new IncomingMessageHandler($api))->handle(makeUpdate());

    expect(Message::count())->toBe(1)
        ->and(Message::first()->sender_type)->toBe(SenderType::User)
        ->and(SupportRequest::sole()->status)->toBe(RequestStatus::Open);
});

it('broadcasts an update for the request after storing messages', function () {
    Event::fake([SupportRequestUpdated::class]);

    handlerWithReplyId()->handle(makeUpdate());

    Event::assertDispatched(SupportRequestUpdated::class, 2);
});

it('closes the request without forwarding on an "ok" answer', function () {
    fakeLlm('{"status":"ok","msg":"Организатор — ООО «Праздник вкуса»."}');

    handlerReplying('Организатор — ООО «Праздник вкуса».')->handle(makeUpdate());

    $request = SupportRequest::sole();
    expect($request->status)->toBe(RequestStatus::Closed)
        ->and($request->closed_at)->not->toBeNull()
        ->and($request->forwarded_to_operator)->toBeFalse()
        ->and($request->forwarded_at)->toBeNull();
});

it('keeps the request open on "partial" and sends the dialogue history on the next message', function () {
    fakeLlm('{"status":"partial","msg":"Опишите вопрос"}');
    handlerReplying('Опишите вопрос', 99)->handle(makeUpdate(['text' => 'Привет', 'message_id' => 10]));

    $calls = fakeLlm('{"status":"ok","msg":"Готово"}');
    handlerReplying('Готово', 100)->handle(makeUpdate(['text' => 'Кто организатор?', 'message_id' => 11], 2));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['messages'])->toBe([
            ['role' => 'user', 'content' => 'Привет'],
            ['role' => 'assistant', 'content' => '{"status":"partial","msg":"Опишите вопрос"}'],
            ['role' => 'user', 'content' => 'Кто организатор?'],
        ])
        ->and($calls[0]['systemPrompt'])->not->toContain('$DMY_TAG')->not->toContain('$RULES_TAG')
        ->toContain('Правила стимулирующей акции')
        ->and(SupportRequest::sole()->status)->toBe(RequestStatus::Closed);
});

it('forwards to an operator on an "error" answer', function () {
    fakeLlm('{"status":"error","msg":null}');

    handlerReplying(FORWARDED)->handle(makeUpdate());

    $request = SupportRequest::sole();
    expect($request->status)->toBe(RequestStatus::Open)
        ->and($request->forwarded_to_operator)->toBeTrue()
        ->and($request->forwarded_at)->not->toBeNull()
        ->and(Message::where('sender_type', 'bot')->sole()->text)->toBe(FORWARDED);
});

it('treats unusable model output as an error', function (string $output) {
    fakeLlm($output);

    handlerReplying(FORWARDED)->handle(makeUpdate());

    expect(SupportRequest::sole()->forwarded_to_operator)->toBeTrue();
})->with([
    'not json' => 'Конечно, вот ответ',
    'not an object' => '"ok"',
    'unknown status' => '{"status":"done","msg":"x"}',
    'missing msg' => '{"status":"ok"}',
    'empty msg' => '{"status":"partial","msg":"  "}',
    'non-string msg' => '{"status":"ok","msg":["x"]}',
    'too long' => fn () => json_encode(['status' => 'ok', 'msg' => str_repeat('я', 4097)]),
]);

it('accepts an answer wrapped in a markdown fence', function () {
    fakeLlm("```json\n{\"status\":\"ok\",\"msg\":\"Ответ\"}\n```");

    handlerReplying('Ответ')->handle(makeUpdate());

    expect(SupportRequest::sole()->status)->toBe(RequestStatus::Closed);
});

it('forwards to an operator when the LLM provider fails', function () {
    fakeLlm(new LlmException('down'));

    handlerReplying(FORWARDED)->handle(makeUpdate());

    expect(SupportRequest::sole()->forwarded_to_operator)->toBeTrue();
});

it('does not reply automatically once the request is forwarded', function () {
    $request = SupportRequest::create(['user_id' => 555, 'forwarded_to_operator' => true]);
    $calls = fakeLlm('{"status":"ok","msg":"x"}');
    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('sendMessage');

    (new IncomingMessageHandler($api))->handle(makeUpdate());

    expect($calls)->toHaveCount(0)
        ->and($request->messages()->count())->toBe(1)
        ->and($request->fresh()->status)->toBe(RequestStatus::Open);
});

it('forwards photos and documents without asking the LLM', function () {
    $calls = fakeLlm('{"status":"ok","msg":"x"}');

    handlerReplying(FORWARDED)->handle(makeUpdate(['text' => null, 'document' => ['file_id' => 'd', 'file_name' => 'a.pdf']]));

    expect($calls)->toHaveCount(0)->and(SupportRequest::sole()->forwarded_to_operator)->toBeTrue();
});

it('puts the Russian date and the rules into the system prompt', function () {
    $prompt = app(PromptBuilder::class)->build(new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC')));

    expect($prompt)->toContain('Current date: 01 октября 2026')
        ->toContain('Вкусная осень')
        ->not->toContain('$DMY_TAG')->not->toContain('$RULES_TAG');
});
