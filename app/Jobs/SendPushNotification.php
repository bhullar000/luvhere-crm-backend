<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Device;
use App\Support\Push\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one push to every device a user has registered.
 *
 * Queued so an HTTP round trip to Expo never sits in the request that triggered
 * it — sending a like should not wait on a push provider.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly int $userId,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = [],
    ) {}

    public function handle(ExpoPushClient $expo): void
    {
        $tokens = Device::query()
            ->where('user_id', $this->userId)
            ->whereNotNull('push_token')
            ->orderBy('id')
            ->pluck('push_token')
            ->all();

        if ($tokens === []) {
            return;
        }

        $dead = $expo->send($tokens, $this->title, $this->body, $this->data);

        // Drop tokens Expo told us are gone, so they are not retried forever.
        if ($dead !== []) {
            Device::query()->whereIn('push_token', $dead)->delete();
        }
    }
}
