<?php

use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Message;
use App\Models\Operator;
use App\Models\SupportRequest;
use App\Services\Telegram\IncomingMessageHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\File as TelegramFile;
use Telegram\Bot\Objects\Message as TelegramMessage;
use Telegram\Bot\Objects\Update;

const TEST_TOKEN = '123456:SECRET-TOKEN';
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function mediaUpdate(array $message, int $id = 20): Update
{
    return new Update(['update_id' => $id, 'message' => array_merge([
        'message_id' => $id,
        'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan'],
        'chat' => ['id' => 555, 'type' => 'private'],
        'date' => 1780000000,
    ], $message)]);
}

function mediaApi(): MockInterface
{
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('getAccessToken')->andReturn(TEST_TOKEN);
    $api->shouldReceive('sendMessage')->andReturn(new TelegramMessage(['message_id' => 99]));

    return $api;
}

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(Operator::factory()->create());
});

it('downloads a photo to private storage and records it as an image', function () {
    $api = mediaApi();
    $api->shouldReceive('downloadFile')
        ->once()
        ->with('large-id', Mockery::type('string'))
        ->andReturnUsing(function ($id, $path) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, base64_decode(PNG));

            return $path;
        });

    (new IncomingMessageHandler($api))->handle(mediaUpdate([
        'photo' => [['file_id' => 'small-id'], ['file_id' => 'large-id']],
        'caption' => 'Вот скрин',
    ]));

    $message = Message::where('sender_type', SenderType::User->value)->firstOrFail();
    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->text)->toBe('Вот скрин')
        ->and($message->image_path)->toStartWith('images/');
    Storage::disk('local')->assertExists($message->image_path);

    $this->get(route('messages.image', $message))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('keeps the message but no path when the photo cannot be downloaded', function () {
    $api = mediaApi();
    $api->shouldReceive('downloadFile')->andThrow(new TelegramSDKException('failed '.TEST_TOKEN));

    (new IncomingMessageHandler($api))->handle(mediaUpdate(['photo' => [['file_id' => 'x']]]));

    $message = Message::where('sender_type', SenderType::User->value)->firstOrFail();
    expect($message->message_type)->toBe(MessageType::Image)
        ->and($message->image_path)->toBeNull();

    $this->get(route('messages.image', $message))->assertNotFound();
});

it('rejects a downloaded photo that is not an image', function () {
    $api = mediaApi();
    $api->shouldReceive('downloadFile')->andReturnUsing(function ($id, $path) {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, '<?php echo 1;');

        return $path;
    });

    (new IncomingMessageHandler($api))->handle(mediaUpdate(['photo' => [['file_id' => 'x']]]));

    expect(Message::where('sender_type', 'user')->first()->image_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('stores a document as file_id and name without downloading it', function () {
    $api = mediaApi();
    $api->shouldNotReceive('downloadFile');
    $api->shouldNotReceive('getFile');

    (new IncomingMessageHandler($api))->handle(mediaUpdate([
        'document' => ['file_id' => 'doc-id', 'file_name' => "../evil/отчёт\n.pdf"],
    ]));

    $message = Message::where('sender_type', 'user')->firstOrFail();
    expect($message->message_type)->toBe(MessageType::File)
        ->and($message->file_id)->toBe('doc-id')
        ->and($message->file_name)->toBe('..evilотчёт.pdf')
        ->and($message->image_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('shows the file link and image url to operators without internals', function () {
    $request = SupportRequest::create(['user_id' => 1]);
    $message = $request->messages()->create([
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'message_type' => MessageType::File,
        'file_id' => 'doc-id',
        'file_name' => 'a.pdf',
    ]);

    $this->get(route('requests.show', $request))
        ->assertInertia(fn ($page) => $page
            ->where('selected.messages.0.file.name', 'a.pdf')
            ->where('selected.messages.0.file.url', route('messages.file', $message))
            ->missing('selected.messages.0.image_path'));
});

it('proxies a file through the backend without leaking the token', function () {
    $request = SupportRequest::create(['user_id' => 1]);
    $message = $request->messages()->create([
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'message_type' => MessageType::File,
        'file_id' => 'doc-id',
        'file_name' => 'отчёт.pdf',
    ]);

    $api = mediaApi();
    $api->shouldReceive('getFile')->with(['file_id' => 'doc-id'])
        ->andReturn(new TelegramFile(['file_path' => 'documents/file_1.pdf']));
    $this->app->instance(Api::class, $api);
    Http::fake(['api.telegram.org/*' => Http::response('PDFBYTES', 200)]);

    $response = $this->get(route('messages.file', $message))->assertOk();

    expect($response->streamedContent())->toBe('PDFBYTES')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and((string) $response->headers)->not->toContain(TEST_TOKEN);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/file/bot'.TEST_TOKEN.'/documents/file_1.pdf');
});

it('returns 502 without leaking the token when Telegram fails', function () {
    $message = SupportRequest::create(['user_id' => 1])->messages()->create([
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'message_type' => MessageType::File,
        'file_id' => 'doc-id',
        'file_name' => 'a.pdf',
    ]);

    $api = mediaApi();
    $api->shouldReceive('getFile')->andThrow(new TelegramSDKException('bad '.TEST_TOKEN));
    $this->app->instance(Api::class, $api);

    $this->get(route('messages.file', $message))
        ->assertStatus(502)
        ->assertDontSee(TEST_TOKEN);
});

it('returns 404 for the wrong kind of message', function () {
    $message = SupportRequest::create(['user_id' => 1])->messages()->create([
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'text' => 'hi',
    ]);

    $this->get(route('messages.file', $message))->assertNotFound();
    $this->get(route('messages.image', $message))->assertNotFound();
});

it('hides attachments from guests', function () {
    auth()->logout();
    $message = SupportRequest::create(['user_id' => 1])->messages()->create([
        'telegram_message_id' => 1,
        'sender_type' => SenderType::User,
        'text' => 'hi',
    ]);

    $this->get(route('messages.image', $message))->assertRedirect(route('login'));
    $this->get(route('messages.file', $message))->assertRedirect(route('login'));
});
