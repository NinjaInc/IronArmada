<?php

namespace OGame\Console\Commands\Scheduler;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Services\BotService;

#[Description('Let every due computer controlled empire (bot) take its turn')]
#[Signature('ogamex:scheduler:run-bots {--limit=25 : Maximum number of bots to run in one invocation}')]
class RunBots extends Command
{
    public function __construct(private readonly BotService $botService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->botService->isEnabled()) {
            $this->info('Bots are disabled, nothing to do.');
            return Command::SUCCESS;
        }

        $acted = $this->botService->runDueBots((int)$this->option('limit'));
        $this->info("Bots that took a turn: {$acted}");

        return Command::SUCCESS;
    }
}
