<?php

namespace Tests\Feature;

use OGame\Services\DiscordNotifier;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Admin-panel Discord webhook setting.
 */
class DiscordWebhookSettingTest extends IsolatedAccountTestCase
{
    private const URL = 'https://discord.com/api/webhooks/123456789012345678/abc_DEF-123';

    public function testValidation(): void
    {
        $this->assertTrue(DiscordNotifier::isValidWebhookUrl(self::URL));
        $this->assertFalse(DiscordNotifier::isValidWebhookUrl('https://evil.example/api/webhooks/1/a'));
        $this->assertFalse(DiscordNotifier::isValidWebhookUrl('http://discord.com/api/webhooks/1/a'));
        $this->assertFalse(DiscordNotifier::isValidWebhookUrl('not a url'));
    }

    public function testStoredSettingWinsOverEnvFallback(): void
    {
        config(['app.discord_alert_webhook' => '']);
        $this->assertSame('', DiscordNotifier::webhookUrl());

        resolve(SettingsService::class)->set(DiscordNotifier::SETTING_KEY, self::URL);
        $this->assertSame(self::URL, DiscordNotifier::webhookUrl());
        $this->assertTrue(resolve(DiscordNotifier::class)->enabled());
    }

    public function testInvalidStoredValueIsIgnored(): void
    {
        config(['app.discord_alert_webhook' => '']);
        resolve(SettingsService::class)->set(DiscordNotifier::SETTING_KEY, 'junk');
        $this->assertSame('', DiscordNotifier::webhookUrl());
    }
}
