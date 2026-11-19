<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Armada Salvage Auction: a single daily lot that players bid dark matter on.
 *
 * @property int $id
 * @property string $auction_date
 * @property string $status
 * @property int|null $active_slot
 * @property string $lot_key
 * @property array<string, mixed> $lot_payload
 * @property int $starting_bid
 * @property int $min_increment_abs
 * @property int $min_increment_pct
 * @property int $current_bid
 * @property int|null $leader_user_id
 * @property int $bid_count
 * @property int $starts_at
 * @property int $ends_at
 * @property int $original_ends_at
 * @property int $extension_count
 * @property bool $final_call_sent
 * @property int|null $settled_at
 * @property int|null $winner_user_id
 * @property int|null $final_price
 * @property int|null $claimed_at
 * @property int|null $claimed_planet_id
 */
#[Fillable([
    'auction_date', 'status', 'active_slot', 'lot_key', 'lot_payload', 'starting_bid', 'min_increment_abs',
    'min_increment_pct', 'current_bid', 'leader_user_id', 'bid_count', 'starts_at', 'ends_at', 'original_ends_at',
    'extension_count', 'final_call_sent', 'settled_at', 'winner_user_id', 'final_price', 'claimed_at',
    'claimed_planet_id',
])]
#[Table(name: 'auctions')]
class Auction extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_UNSOLD = 'unsold';
    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'lot_payload' => 'array',
            'final_call_sent' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AuctionBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }
}
