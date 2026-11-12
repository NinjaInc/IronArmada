<?php

namespace Tests\Feature;

use OGame\Models\User;
use OGame\Services\UserPreferencesService;
use Tests\IsolatedAccountTestCase;

/**
 * Interface preferences saved from the Options page.
 */
class OptionsPreferencesTest extends IsolatedAccountTestCase
{
    public function testPreferencesSaveAndReload(): void
    {
        $response = $this->post('/options', [
            'selectedTab' => '1',
            'settings_sort' => '2',
            'settings_order' => '1',
            'msgResultsPerPage' => '25',
            'eventsShow' => '3',
            'disable_chat_bar' => '1',
            'animatedOverview' => '1',
            'popups' => ['notices' => '1'],
            '_token' => csrf_token(),
        ]);

        $response->assertRedirect('/options');
        $response->assertSessionHas('success');

        $prefs = UserPreferencesService::forUser(User::findOrFail($this->currentUserId));
        $this->assertSame(2, $prefs['settings_sort']);
        $this->assertSame(1, $prefs['settings_order']);
        $this->assertSame(25, $prefs['msgResultsPerPage']);
        $this->assertSame(3, $prefs['eventsShow']);
        $this->assertSame(1, $prefs['disable_chat_bar']);
        $this->assertSame(1, $prefs['popups_notices']);
        // Unchecked boxes save as off, even ones that default to on.
        $this->assertSame(0, $prefs['animatedSliders']);
        $this->assertSame(0, $prefs['popups_combatreport']);

        // The form shows what was saved.
        $this->get('/options')->assertSee('name="disable_chat_bar" value="1" checked', false);
    }

    public function testInvalidChoicesFallBackToDefaults(): void
    {
        $this->post('/options', [
            'selectedTab' => '1',
            'settings_sort' => '99',
            'msgResultsPerPage' => '7',
            '_token' => csrf_token(),
        ]);

        $prefs = UserPreferencesService::forUser(User::findOrFail($this->currentUserId));
        $this->assertSame(0, $prefs['settings_sort']);
        $this->assertSame(10, $prefs['msgResultsPerPage']);
    }

    public function testChatBarHiddenWhenDisabled(): void
    {
        $this->post('/options', ['selectedTab' => '1', 'disable_chat_bar' => '1', '_token' => csrf_token()]);

        $this->get('/overview')->assertSee('#chatBar { display: none !important; }', false);
    }
}
