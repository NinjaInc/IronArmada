<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only ledger: a row is never deleted, and the only update ever made is setting refunded_at.
     */
    public function up(): void
    {
        Schema::create('auction_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('placed_at');
            $table->boolean('extended_end')->default(false);         // this bid triggered anti-snipe
            $table->boolean('is_bot')->default(false);
            $table->unsignedInteger('refunded_at')->nullable();      // set when outbid (or auction cancelled)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['auction_id', 'amount']);                // two bids at the same amount are impossible
            $table->index(['auction_id', 'refunded_at']);            // "the one held bid"
            $table->index(['user_id', 'placed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('auction_bids');
    }
};
