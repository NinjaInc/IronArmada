<?php

namespace OGame\Services;

use OGame\Models\User;

/**
 * Per-player interface preferences saved from the Options page.
 */
class UserPreferencesService
{
    /**
     * Checkbox preferences and their default when never saved.
     *
     * @var array<string, int>
     */
    public const FLAGS = [
        'activate_autofocus' => 1,
        'showDetailOverlay' => 1,
        'animatedSliders' => 1,
        'animatedOverview' => 1,
        'auctioneerNotifications' => 1,
        'showActivityMinutes' => 1,
        'economyNotifications' => 0,
        'spioReportPictures' => 0,
        'preserveSystemOnPlanetChange' => 0,
        'popups_notices' => 0,
        'popups_combatreport' => 0,
        'disable_chat_bar' => 0,
        'disable_outlaw_warning' => 0,
        'show_mobile_version' => 0,
        'show_old_dropdowns' => 0,
    ];

    /**
     * Dropdown preferences: allowed values, first value is the default.
     *
     * @var array<string, array<int>>
     */
    public const CHOICES = [
        'eventsShow' => [1, 2, 3],
        'settings_sort' => [0, 1, 2, 3, 4],
        'settings_order' => [0, 1],
        'msgResultsPerPage' => [10, 25, 50],
    ];

    /**
     * @return array<string, int>
     */
    public static function forUser(User $user): array
    {
        $stored = $user->preferences ?? [];
        $result = [];
        foreach (self::FLAGS as $key => $default) {
            $result[$key] = (int)($stored[$key] ?? $default);
        }
        foreach (self::CHOICES as $key => $allowed) {
            $value = (int)($stored[$key] ?? $allowed[0]);
            $result[$key] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        return $result;
    }

    public static function get(User $user, string $key): int
    {
        return self::forUser($user)[$key] ?? 0;
    }

    /**
     * Save submitted Options form values. Unchecked checkboxes are absent from the request, so they save as 0.
     *
     * @param array<string, mixed> $input
     */
    public static function saveFromRequest(User $user, array $input): void
    {
        $prefs = [];
        foreach (array_keys(self::FLAGS) as $key) {
            // popups[notices] arrives as a nested array.
            if (str_starts_with($key, 'popups_')) {
                $prefs[$key] = !empty($input['popups'][substr($key, 7)]) ? 1 : 0;
            } else {
                $prefs[$key] = !empty($input[$key]) ? 1 : 0;
            }
        }
        foreach (self::CHOICES as $key => $allowed) {
            $value = (int)($input[$key] ?? $allowed[0]);
            $prefs[$key] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        $user->preferences = $prefs;
        $user->save();
    }
}
