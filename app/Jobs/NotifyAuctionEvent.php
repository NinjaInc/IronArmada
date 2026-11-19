<?php

namespace OGame\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OGame\Models\Auction;
use OGame\Services\AuctionNotifier;

/**
 * Sends one Armada Salvage Auction notification (Discord and/or in-game message) off the request/tick path.
 * Dispatched after commit; best-effort, so it never fails loudly.
 */
class NotifyAuctionEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const EVENTS = ['opened', 'final_call', 'won', 'unsold', 'extended', 'outbid'];

    public int $tries = 3;

    /**
     * @param string $event One of self::EVENTS.
     * @param int $auctionId
     * @param array<string, mixed> $context Event data; for 'outbid': outbid_user_id and amount.
     */
    public function __construct(
        public string $event,
        public int $auctionId,
        public array $context = [],
    ) {
        $this->afterCommit();
    }

    public function handle(AuctionNotifier $notifier): void
    {
        if (!in_array($this->event, self::EVENTS, true)) {
            Log::warning("NotifyAuctionEvent: unknown event '{$this->event}'");
            return;
        }

        $auction = Auction::find($this->auctionId);
        if ($auction === null) {
            Log::warning("NotifyAuctionEvent: auction #{$this->auctionId} not found for '{$this->event}'");
            return;
        }

        $notifier->handle($this->event, $auction, $this->context);
    }
}
