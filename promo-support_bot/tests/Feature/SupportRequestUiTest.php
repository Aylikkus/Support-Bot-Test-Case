<?php

use App\Enums\RequestStatus;
use App\Enums\SenderType;
use App\Events\SupportRequestUpdated;
use App\Models\Message;
use App\Models\Operator;
use App\Models\SupportRequest;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Message as TelegramMessage;

beforeEach(function () {
    $this->operator = Operator::factory()->create();
    $this->actingAs($this->operator);
});

function openRequest(int $userId = 555, string $text = 'Привет'): SupportRequest
{
    $request = SupportRequest::create(['user_id' => $userId]);
    $request->messages()->create([
        'user_id' => $userId,
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'text' => $text,
    ]);

    return $request;
}

it('lists only open requests, oldest first', function () {
    $first = openRequest(1);
    SupportRequest::create(['user_id' => 2, 'status' => RequestStatus::Closed]);
    $third = openRequest(3);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Requests/Index')
            ->where('selected', null)
            ->has('requests', 2)
            ->where('requests.0.id', $first->request_id)
            ->where('requests.1.id', $third->request_id)
            ->where('requests.0.last_message.text', 'Привет')
            ->where('requests.0.messages_count', 1));
});

it('shows the message history of a request', function () {
    $request = openRequest(7, 'Первое');
    $request->messages()->create([
        'telegram_message_id' => 2,
        'sender_type' => SenderType::Bot,
        'text' => 'Второе',
    ]);

    $this->get(route('requests.show', $request))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $request->request_id)
            ->where('selected.status', 'open')
            ->has('selected.messages', 2)
            ->where('selected.messages.0.text', 'Первое')
            ->where('selected.messages.1.sender_type', 'bot'));
});

it('sends an operator message to the user, stores it and broadcasts', function () {
    Event::fake([SupportRequestUpdated::class]);
    $request = openRequest(555);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('sendMessage')
        ->once()
        ->with(['chat_id' => 555, 'text' => 'Здравствуйте'])
        ->andReturn(new TelegramMessage(['message_id' => 77]));
    $this->app->instance(Api::class, $api);

    $this->post(route('requests.messages.store', $request), ['text' => 'Здравствуйте'])
        ->assertRedirect();

    $stored = Message::latest('message_id')->first();
    expect($stored->text)->toBe('Здравствуйте')
        ->and($stored->sender_type)->toBe(SenderType::Operator)
        ->and($stored->operator_id)->toBe($this->operator->operator_id)
        ->and($stored->telegram_message_id)->toBe(77)
        ->and($stored->request_id)->toBe($request->request_id);

    Event::assertDispatched(SupportRequestUpdated::class, fn ($e) => $e->requestId === $request->request_id);
});

it('validates the operator message', function () {
    $request = openRequest();

    $this->post(route('requests.messages.store', $request), ['text' => ''])
        ->assertSessionHasErrors('text');
    $this->post(route('requests.messages.store', $request), ['text' => str_repeat('a', 4097)])
        ->assertSessionHasErrors('text');

    expect(Message::count())->toBe(1);
});

it('does not store the message when Telegram rejects it', function () {
    $request = openRequest();

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('sendMessage')->andThrow(new TelegramSDKException('blocked'));
    $this->app->instance(Api::class, $api);

    $this->post(route('requests.messages.store', $request), ['text' => 'Ответ'])
        ->assertSessionHasErrors('text');

    expect(Message::count())->toBe(1);
});

it('refuses to send messages to a closed request', function () {
    $request = SupportRequest::create(['user_id' => 1, 'status' => RequestStatus::Closed]);

    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('sendMessage');
    $this->app->instance(Api::class, $api);

    $this->post(route('requests.messages.store', $request), ['text' => 'Ответ'])
        ->assertSessionHasErrors('text');
});

it('closes a request and broadcasts the change', function () {
    Event::fake([SupportRequestUpdated::class]);
    $request = openRequest();

    $this->post(route('requests.close', $request))->assertRedirect(route('home'));

    $request->refresh();
    expect($request->status)->toBe(RequestStatus::Closed)
        ->and($request->closed_at)->not->toBeNull();

    Event::assertDispatched(SupportRequestUpdated::class);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
});

it('returns operator statistics as JSON on demand', function () {
    $base = now();

    SupportRequest::create([
        'user_id' => 10,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => false,
    ]);

    $r1 = SupportRequest::create([
        'user_id' => 11,
        'status' => RequestStatus::Open,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(90),
        'closed_at' => null,
    ]);

    $r2 = SupportRequest::create([
        'user_id' => 12,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(40),
        'closed_at' => $base->copy()->subMinutes(10),
    ]);

    $r3 = SupportRequest::create([
        'user_id' => 13,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(20),
        'closed_at' => $base->copy()->subMinutes(10),
    ]);

    // Invalid interval: operator message before forwarding, should be excluded from average.
    $r4 = SupportRequest::create([
        'user_id' => 14,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(5),
        'closed_at' => $base->copy()->subMinutes(1),
    ]);

    // Forwarded request without operator message: should be excluded from average.
    SupportRequest::create([
        'user_id' => 15,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(50),
        'closed_at' => $base->copy()->subMinutes(30),
    ]);

    $r2->messages()->create([
        'telegram_message_id' => 5001,
        'sender_type' => SenderType::Operator,
        'operator_id' => $this->operator->operator_id,
        'text' => 'Первый ответ',
        'created_at' => $base->copy()->subMinutes(20),
    ]);

    // Later operator message must not affect average (only the first one counts).
    $r2->messages()->create([
        'telegram_message_id' => 5002,
        'sender_type' => SenderType::Operator,
        'operator_id' => $this->operator->operator_id,
        'text' => 'Второй ответ',
        'created_at' => $base->copy()->subMinutes(15),
    ]);

    $r3->messages()->create([
        'telegram_message_id' => 5003,
        'sender_type' => SenderType::Operator,
        'operator_id' => $this->operator->operator_id,
        'text' => 'Ответ',
        'created_at' => $base->copy()->subMinutes(15),
    ]);

    $r4->messages()->create([
        'telegram_message_id' => 5004,
        'sender_type' => SenderType::Operator,
        'operator_id' => $this->operator->operator_id,
        'text' => 'Слишком рано',
        'created_at' => $base->copy()->subMinutes(6),
    ]);

    $this->get(route('requests.stats'))
        ->assertOk()
        ->assertJson([
            'bot_closed_count' => 1,
            'forwarded_count' => 5,
            'avg_operator_response_time' => '00 ч 12 м 30 с',
        ]);
});

it('returns zeroed average when there are no valid forwarded-to-first-operator-message intervals', function () {
    $base = now();

    SupportRequest::create([
        'user_id' => 1,
        'status' => RequestStatus::Closed,
        'forwarded_to_operator' => false,
    ]);

    $invalid = SupportRequest::create([
        'user_id' => 2,
        'status' => RequestStatus::Open,
        'forwarded_to_operator' => true,
        'forwarded_at' => $base->copy()->subMinutes(3),
        'closed_at' => null,
    ]);

    // Operator message before forwarding: excluded by whereColumn check.
    $invalid->messages()->create([
        'telegram_message_id' => 7001,
        'sender_type' => SenderType::Operator,
        'operator_id' => $this->operator->operator_id,
        'text' => 'Ранний ответ',
        'created_at' => $base->copy()->subMinutes(4),
    ]);

    $this->get(route('requests.stats'))
        ->assertOk()
        ->assertJson([
            'bot_closed_count' => 1,
            'forwarded_count' => 1,
            'avg_operator_response_time' => '00 ч 00 м 00 с',
        ]);
});

it('broadcasts on the support-requests channel', function () {
    $event = new SupportRequestUpdated(5);

    expect($event->broadcastOn()[0]->name)->toBe('support-requests')
        ->and($event->broadcastAs())->toBe('updated')
        ->and($event->broadcastWith())->toBe(['request_id' => 5]);
});
