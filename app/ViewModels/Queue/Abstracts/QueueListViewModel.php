<?php

namespace OGame\ViewModels\Queue\Abstracts;

use OGame\Services\SettingsService;

class QueueListViewModel
{
    /**
     * Constructor.
     *
     * @param array<QueueViewModel> $queue
     */
    public function __construct(
        /**
         * List of queue items.
         */
        public array $queue
    ) {
    }

    /**
     * Get amount of items in the queue.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Get amount of items in the queue.
     *
     * @return bool
     */
    public function isQueueFull(): bool
    {
        // Items are paid for when they are queued, so the real limit is what the player can afford.
        // This is only a sanity cap, configurable with the server setting "queue_max_items".
        $maxItemsInQueue = (int)resolve(SettingsService::class)->get('queue_max_items', 25);
        return count($this->queue) >= $maxItemsInQueue;
    }
}
