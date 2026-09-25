<?php

declare(strict_types=1);

namespace App\Support\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Expo's push service.
 *
 * Expo accepts up to 100 messages per request, so tokens are chunked. The
 * response carries a per-message ticket; a `DeviceNotRegistered` error means the
 * user uninstalled the app or revoked permission, and the caller is told which
 * tokens to drop so we stop pushing into the void.
 */
class ExpoPushClient
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private const CHUNK = 100;

    /** Expo tokens always look like ExponentPushToken[...] or ExpoPushToken[...]. */
    public static function isExpoToken(string $token): bool
    {
        return (bool) preg_match('/^Expo(nent)?PushToken\[.+\]$/', $token);
    }

    /**
     * Push one payload to many tokens.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return list<string> tokens that are no longer valid and should be deleted
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, self::isExpoToken(...))));

        if ($tokens === []) {
            return [];
        }

        $dead = [];

        foreach (array_chunk($tokens, self::CHUNK) as $chunk) {
            $messages = array_map(fn (string $to) => [
                'to' => $to,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
                'channelId' => 'default',
                'priority' => 'high',
            ], $chunk);

            $dead = [...$dead, ...$this->dispatch($chunk, $messages)];
        }

        return $dead;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<array<string, mixed>>  $messages
     * @return list<string>
     */
    private function dispatch(array $tokens, array $messages): array
    {
        $request = Http::asJson()
            ->acceptJson()
            ->timeout(10)
            ->retry(2, 200, throw: false);

        if ($accessToken = config('services.expo.access_token')) {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post(self::ENDPOINT, $messages);

        if ($response->failed()) {
            Log::warning('Expo push request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $dead = [];

        // Tickets come back positionally, one per token in the chunk.
        foreach ((array) $response->json('data', []) as $i => $ticket) {
            if (($ticket['status'] ?? null) !== 'error') {
                continue;
            }

            $reason = $ticket['details']['error'] ?? null;

            if ($reason === 'DeviceNotRegistered' && isset($tokens[$i])) {
                $dead[] = $tokens[$i];

                continue;
            }

            Log::warning('Expo push ticket error', [
                'error' => $reason,
                'message' => $ticket['message'] ?? null,
            ]);
        }

        return $dead;
    }
}
