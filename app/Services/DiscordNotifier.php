<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts short game event messages to the Discord webhook set in Admin > Server Settings (falls back to DISCORD_ALERT_WEBHOOK).
 * Does nothing when no webhook is configured, and never lets a Discord problem break the game.
 */
class DiscordNotifier
{
    public const SETTING_KEY = 'discord_webhook_url';

    /**
     * Accepts only genuine Discord webhook URLs.
     */
    public static function isValidWebhookUrl(string $url): bool
    {
        return (bool)preg_match('#^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\d+/[\w-]+$#', $url);
    }

    /**
     * Webhook URL: admin-panel setting first, then the DISCORD_ALERT_WEBHOOK env fallback.
     * Never throws (safe to call while rendering exceptions, e.g. when the DB is down).
     */
    public static function webhookUrl(): string
    {
        try {
            $stored = trim(resolve(SettingsService::class)->get(self::SETTING_KEY, ''));
            if ($stored !== '' && self::isValidWebhookUrl($stored)) {
                return $stored;
            }
        } catch (Throwable) {
            // fall through to env value
        }

        return (string)config('app.discord_alert_webhook', '');
    }

    public function enabled(): bool
    {
        return self::webhookUrl() !== '';
    }

    public function send(string $message): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            Http::timeout(3)->post(self::webhookUrl(), [
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
