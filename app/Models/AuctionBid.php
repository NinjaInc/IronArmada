<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bid in the append-only auction bid ledger. Only refunded_at is ever updated.
 *
 * @property int $id
 * @property int $auction_id
 * @property int $user_id
 * @property int $amount
 * @property int $placed_at
 * @property bool $extended_end
 * @property bool $is_bot
 * @property int|null $refunded_at
 */
#[Fillable(['auction_id', 'user_id', 'amount', 'placed_at', 'extended_end', 'is_bot', 'refunded_at'])]
#[Table(name: 'auction_bids')]
class AuctionBid extends Model
{
    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'extended_end' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
