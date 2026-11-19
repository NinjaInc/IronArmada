<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            ['key' => 'auction_enabled', 'value' => '0'], // Admin switches it on once the art is in
            ['key' => 'auction_open_hour_utc', 'value' => '18'],
            ['key' => 'auction_duration_minutes', 'value' => '1320'],
            ['key' => 'auction_snipe_window_seconds', 'value' => '300'],
            ['key' => 'auction_snipe_extension_seconds', 'value' => '300'],
            ['key' => 'auction_max_extension_seconds', 'value' => '3600'],
            ['key' => 'auction_min_increment_abs', 'value' => '50'],
            ['key' => 'auction_min_increment_pct', 'value' => '5'],
            ['key' => 'auction_bots_enabled', 'value' => '0'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->insertOrIgnore($setting);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $keys = [
            'auction_enabled',
            'auction_open_hour_utc',
            'auction_duration_minutes',
            'auction_snipe_window_seconds',
            'auction_snipe_extension_seconds',
            'auction_max_extension_seconds',
            'auction_min_increment_abs',
            'auction_min_increment_pct',
            'auction_bots_enabled',
        ];

        DB::table('settings')->whereIn('key', $keys)->delete();
    }
};
