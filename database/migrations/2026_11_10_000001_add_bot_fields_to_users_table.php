<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the fields used by the computer controlled empires ("bots") feature.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->index();
            $table->string('bot_personality', 20)->nullable();
            $table->unsignedTinyInteger('bot_difficulty')->default(2);
            $table->boolean('bot_enabled')->default(true);
            $table->unsignedInteger('bot_next_action_at')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_bot']);
            $table->dropColumn(['is_bot', 'bot_personality', 'bot_difficulty', 'bot_enabled', 'bot_next_action_at']);
        });
    }
};
