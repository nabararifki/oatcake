<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWebPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of retry attempts for transient VAPID gateway failures.
     */
    public int $tries = 3;

    /**
     * Maximum job execution timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * Exponential backoff intervals (in seconds) between retries.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * @param  list<int>  $recipientUserIds
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $recipientUserIds,
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl = null,
        public readonly array $meta = [],
    ) {}

    /**
     * Execute the queued Web Push & in-app notification dispatch.
     */
    public function handle(WebPushService $webPushService): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $this->recipientUserIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $webPushService->notifyUsers(
            $recipients,
            $this->category,
            $this->title,
            $this->body,
            $this->actionUrl,
            $this->meta
        );
    }
}
