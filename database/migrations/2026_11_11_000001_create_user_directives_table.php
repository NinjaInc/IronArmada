<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_directives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('directive_key', 40);
            // e.g. "d:2026-11-11" for daily or "w:2026-W46" for weekly directives.
            $table->string('period_key', 20);
            $table->unsignedInteger('period_start');
            $table->bigInteger('baseline')->default(0);
            $table->boolean('claimed')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'directive_key', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_directives');
    }
};
