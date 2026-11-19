<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use OGame\Jobs\NotifyAuctionEvent;
use OGame\Models\Auction;
use OGame\Services\AuctionNotifier;
use OGame\Services\AuctionService;
use Throwable;

#[Description('Armada Salvage Auction tick: settle due auctions, send the final call, open today\'s auction')]
#[Signature('ogamex:scheduler:auction-tick')]
class AuctionTick extends Command
{
    /** Seconds before the end at which the final call goes out. */
    private const int FINAL_CALL_SECONDS = 900;

    public function __construct(private readonly AuctionService $auctions)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->auctions->isEnabled()) {
            $this->info('Auctions are disabled, nothing to do.');
            return Command::SUCCESS;
        }

        // 1. Settle every due auction. settleAllDue() only returns a count, so remember which ids were due
        // beforehand and read back how each of them ended up.
        $now = (int)Date::now()->timestamp;
        $dueIds = Auction::where('status', Auction::STATUS_ACTIVE)->where('ends_at', '<=', $now)->pluck('id');
        $settledCount = $this->auctions->settleAllDue();
        $this->info("Auctions settled: {$settledCount}");

        if ($settledCount > 0) {
            foreach (Auction::whereIn('id', $dueIds)->get() as $auction) {
                if ($auction->status === Auction::STATUS_SETTLED) {
                    $this->notify('won', $auction);
                } elseif ($auction->status === Auction::STATUS_UNSOLD) {
                    $this->notify('unsold', $auction);
                }
            }
        }

        // 2. Final call. The conditional UPDATE makes sure only one run announces it.
        $now = (int)Date::now()->timestamp;
        $closing = Auction::where('status', Auction::STATUS_ACTIVE)
            ->where('final_call_sent', false)
            ->where('ends_at', '>', $now)
            ->where('ends_at', '<=', $now + self::FINAL_CALL_SECONDS)
            ->get();
        foreach ($closing as $auction) {
            $updated = Auction::where('id', $auction->id)->where('final_call_sent', false)->update(['final_call_sent' => true]);
            if ($updated === 1) {
                $this->info("Final call for auction #{$auction->id}");
                $this->notify('final_call', $auction);
            }
        }

        // 3. Open today's auction if due.
        $opened = $this->auctions->openIfDue();
        if ($opened !== null) {
            $this->info("Opened auction #{$opened->id}");
            $this->notify('opened', $opened);
        }

        return Command::SUCCESS;
    }

    /**
     * Best-effort notification. The T9 classes (NotifyAuctionEvent job, AuctionNotifier) may not exist yet, so
     * every call is guarded and a failure here never fails the tick.
     */
    private function notify(string $event, Auction $auction): void
    {
        try {
            if (class_exists(NotifyAuctionEvent::class)) {
                NotifyAuctionEvent::dispatch($event, $auction->id);
                return;
            }

            if (class_exists(AuctionNotifier::class)) {
                $notifier = app(AuctionNotifier::class);
                match ($event) {
                    'opened' => $notifier->opened($auction),
                    'final_call' => $notifier->finalCall($auction),
                    'won' => $notifier->won($auction),
                    'unsold' => $notifier->unsold($auction),
                    default => null,
                };
            }
        } catch (Throwable $e) {
            logger()->warning("Auction notification '{$event}' for auction #{$auction->id} failed: " . $e->getMessage());
        }
    }
}
