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
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->date('auction_date')->unique();                 // UTC opening date: makes daily creation idempotent
            $table->string('status', 16)->index();                  // active | settled | unsold | cancelled
            $table->unsignedTinyInteger('active_slot')->nullable()->unique(); // 1 while active, NULL otherwise => max one active auction
            $table->string('lot_key', 40);
            $table->json('lot_payload');                            // frozen contents
            $table->unsignedInteger('starting_bid');
            $table->unsignedInteger('min_increment_abs');           // frozen from settings at open
            $table->unsignedTinyInteger('min_increment_pct');       // frozen from settings at open
            $table->unsignedInteger('current_bid')->default(0);
            $table->unsignedInteger('leader_user_id')->nullable();
            $table->unsignedInteger('bid_count')->default(0);
            $table->unsignedInteger('starts_at');
            $table->unsignedInteger('ends_at');
            $table->unsignedInteger('original_ends_at');
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->boolean('final_call_sent')->default(false);     // Discord "final 15 minutes" sent once
            $table->unsignedInteger('settled_at')->nullable();
            $table->unsignedInteger('winner_user_id')->nullable();
            $table->unsignedInteger('final_price')->nullable();
            $table->unsignedInteger('claimed_at')->nullable();
            $table->unsignedInteger('claimed_planet_id')->nullable();
            $table->timestamps();

            $table->foreign('leader_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('winner_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'ends_at']);                   // tick: "active and due"
            $table->index(['winner_user_id', 'claimed_at']);        // "my unclaimed winnings"
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
