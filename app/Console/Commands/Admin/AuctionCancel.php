<?php

namespace OGame\Console\Commands\Admin;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Services\AuctionService;

#[Description('Cancel the active Armada Salvage Auction and refund the held bid')]
#[Signature('ogamex:admin:auction-cancel {auction_id : ID of the active auction} {--reason= : Reason, written to the log}')]
class AuctionCancel extends Command
{
    public function __construct(private readonly AuctionService $auctions)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $auctionId = $this->argument('auction_id');
        if (!is_string($auctionId) || !ctype_digit($auctionId) || (int)$auctionId < 1) {
            $this->error('auction_id must be a positive integer.');
            return Command::FAILURE;
        }

        $reason = $this->option('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : 'cancelled by admin';

        if (!$this->auctions->cancel((int)$auctionId, $reason)) {
            $this->error("Auction #{$auctionId} was not cancelled (it does not exist or is not active).");
            return Command::FAILURE;
        }

        $this->info("Auction #{$auctionId} cancelled and the held bid refunded.");

        return Command::SUCCESS;
    }
}
