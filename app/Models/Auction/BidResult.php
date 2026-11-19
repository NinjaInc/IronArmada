<?php

namespace OGame\Models\Auction;

/**
 * Outcome of AuctionService::placeBid(), consumed by the controller and the notifier.
 */
final class BidResult
{
    /**
     * @param bool $accepted Whether the bid was placed.
     * @param string $errorKey Empty when accepted, otherwise a t_merchant.auction.error.* suffix.
     * @param int $currentBid Current highest bid after this call (0 when there is none).
     * @param int $minimumNextBid Minimum amount the next bid must reach.
     * @param int $endsAt Auction end timestamp after this call.
     * @param bool $extended Whether this bid triggered the anti-snipe extension.
     * @param int|null $outbidUserId Previous leader who was refunded, if any.
     * @param int $refundedAmount Amount refunded to the previous leader.
     */
    public function __construct(
        public bool $accepted,
        public string $errorKey,
        public int $currentBid,
        public int $minimumNextBid,
        public int $endsAt,
        public bool $extended,
        public int|null $outbidUserId,
        public int $refundedAmount,
    ) {
    }
}
