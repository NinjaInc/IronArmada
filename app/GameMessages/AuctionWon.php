<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

class AuctionWon extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'auction_won';
        $this->params = ['lot', 'amount', 'link'];
        $this->tab = 'universe';
        $this->subtab = 'universe';
    }
}
