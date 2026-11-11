<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts short game event messages to the Discord webhook configured in DISCORD_ALERT_WEBHOOK.
 * Does nothing when no webhook is configured, and never lets a Discord problem break the game.
 */
class DiscordNotifier
{
    public function enabled(): bool
    {
        return !empty(config('app.discord_alert_webhook'));
    }

    public function send(string $message): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            Http::timeout(3)->post((string)config('app.discord_alert_webhook'), [
                'username' => 'Iron Armada',
                'content' => mb_substr($message, 0, 1900),
                // Never let player-controlled names ping anyone.
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (Throwable $e) {
            Log::info('Discord notification failed: ' . $e->getMessage());
        }
    }
}
