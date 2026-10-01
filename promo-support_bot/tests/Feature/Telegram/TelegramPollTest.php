<?php

use App\Models\Message;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message as TelegramMessage;
use Telegram\Bot\Objects\Update;

it('processes a batch of updates and confirms them with the next offset', function () {
    $update = new Update([
        'update_id' => 41,
        'message' => [
            'message_id' => 7,
            'from' => ['id' => 1, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => 1, 'type' => 'private'],
            'date' => 1780000000,
            'text' => 'hi',
        ],
    ]);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('deleteWebhook')->once();
    $api->shouldReceive('getUpdates')->once()->andReturn([$update]);
    $api->shouldReceive('sendMessage')->once()->andReturn(new TelegramMessage(['message_id' => 8]));
    $this->app->instance(Api::class, $api);

    $this->artisan('telegram:poll --once')->assertSuccessful();

    expect(Message::count())->toBe(2);
});
