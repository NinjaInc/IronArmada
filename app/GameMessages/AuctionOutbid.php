<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

class AuctionOutbid extends GameMessage
{
    protected function initialize(): void
    {
        $this->key = 'auction_outbid';
        $this->params = ['lot', 'amount', 'link'];
        $this->tab = 'universe';
        $this->subtab = 'universe';
    }
}
