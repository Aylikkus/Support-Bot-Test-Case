<?php

namespace App\Http\Controllers;

use App\Enums\MessageType;
use App\Enums\RequestStatus;
use App\Models\Message;
use App\Models\SupportRequest;
use App\Services\SupportRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

use function max;
use function round;
use function sprintf;

class SupportRequestController extends Controller
{
    public function index(): Response
    {
        return $this->render(null);
    }

    public function show(SupportRequest $supportRequest): Response
    {
        return $this->render($supportRequest);
    }

    public function storeMessage(Request $http, SupportRequest $supportRequest, SupportRequestService $service): RedirectResponse
    {
        $data = $http->validate([
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $service->sendOperatorMessage($supportRequest, $http->user(), $data['text']);

        return back();
    }

    public function close(SupportRequest $supportRequest, SupportRequestService $service): RedirectResponse
    {
        $service->close($supportRequest);

        return to_route('home');
    }

    public function stats(): JsonResponse
    {
        $botClosedCount = SupportRequest::query()
            ->where('status', RequestStatus::Closed->value)
            ->where('forwarded_to_operator', false)
            ->count();

        $forwardedCount = SupportRequest::query()
            ->where('forwarded_to_operator', true)
            ->count();

        $avgSeconds = (float) (SupportRequest::query()
            ->where('forwarded_to_operator', true)
            ->whereNotNull('forwarded_at')
            ->joinSub(
                Message::query()
                    ->select('request_id')
                    ->selectRaw('MIN(created_at) as first_operator_message_at')
                    ->where('sender_type', 'operator')
                    ->groupBy('request_id'),
                'first_operator_message',
                'first_operator_message.request_id',
                '=',
                'requests.request_id',
            )
            ->whereColumn('first_operator_message.first_operator_message_at', '>=', 'requests.forwarded_at')
            ->selectRaw($this->avgDurationSecondsSql(
                'first_operator_message.first_operator_message_at',
                'requests.forwarded_at',
            ).' as avg_seconds')
            ->value('avg_seconds') ?? 0);

        return response()->json([
            'bot_closed_count' => $botClosedCount,
            'forwarded_count' => $forwardedCount,
            'avg_operator_response_time' => $this->formatDuration($avgSeconds),
        ]);
    }

    private function render(?SupportRequest $selected): Response
    {
        return Inertia::render('Requests/Index', [
            'requests' => fn () => $this->queue(),
            'selected' => fn () => $selected ? $this->details($selected) : null,
        ]);
    }

    /**
     * Open requests, oldest first, i.e. in the order they should be served.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queue(): array
    {
        return SupportRequest::query()
            ->where('status', RequestStatus::Open->value)
            ->with('latestMessage')
            ->withCount('messages')
            ->orderBy('request_id')
            ->get()
            ->map(fn (SupportRequest $request): array => [
                'id' => $request->request_id,
                'user_id' => $request->user_id,
                'created_at' => $request->created_at,
                'messages_count' => $request->messages_count,
                'last_message' => $request->latestMessage ? $this->message($request->latestMessage) : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function details(SupportRequest $request): array
    {
        return [
            'id' => $request->request_id,
            'user_id' => $request->user_id,
            'status' => $request->status,
            'created_at' => $request->created_at,
            'closed_at' => $request->closed_at,
            'messages' => $request->messages()
                ->orderBy('message_id')
                ->get()
                ->map(fn (Message $message): array => $this->message($message))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(Message $message): array
    {
        return [
            'id' => $message->message_id,
            'sender_type' => $message->sender_type,
            'message_type' => $message->message_type,
            'text' => $message->text,
            'image_url' => $message->message_type === MessageType::Image && $message->image_path !== null
                ? route('messages.image', $message)
                : null,
            'file' => $message->message_type === MessageType::File && $message->file_id !== null
                ? ['name' => $message->file_name ?? 'file', 'url' => route('messages.file', $message)]
                : null,
            'created_at' => $message->created_at,
        ];
    }

    private function formatDuration(float $seconds): string
    {
        $totalSeconds = max(0, (int) round($seconds));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;

        return sprintf('%02d ч %02d м %02d с', $hours, $minutes, $secs);
    }

    private function avgDurationSecondsSql(string $to, string $from): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => sprintf('AVG((julianday(%s) - julianday(%s)) * 86400)', $to, $from),
            default => sprintf('AVG(EXTRACT(EPOCH FROM (%s - %s)))', $to, $from),
        };
    }
}
