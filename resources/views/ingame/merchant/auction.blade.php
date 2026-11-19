@extends('ingame.layouts.main')

@section('content')

    @php
        $auction = $state['auction'] ?? null;
        $enabled = (bool)($state['enabled'] ?? false);
        $serverNow = (int)($state['server_now'] ?? 0);
        $recentBids = $state['recent_bids'] ?? [];
        $unclaimed = $state['unclaimed'] ?? [];
        $nextOpensAt = isset($state['next_opens_at']) ? (int)$state['next_opens_at'] : null;

        // Server-rendered first paint of a countdown. T12 recomputes it from data-ends-at / data-server-now.
        $formatRemaining = function (int $seconds): string {
            $seconds = max(0, $seconds);
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;
            if ($hours > 0) {
                return $hours . 'h ' . $minutes . 'm';
            }
            if ($minutes > 0) {
                return $minutes . 'm ' . $secs . 's';
            }

            return $secs . 's';
        };

        // Rounds up to the next 10 DM, the same granularity the service uses for minimum bids.
        $roundUp10 = fn (int $value): int => intdiv($value + 9, 10) * 10;

        $lotKey = $auction['lot_key'] ?? null;
        $lotName = $lotKey !== null ? __('t_merchant.auction.lots.' . $lotKey) : '';
        $lotResources = $auction['lot']['resources'] ?? [];
        $lotUnits = $auction['lot']['units'] ?? [];

        // Lot icon: auction art first, then the first unit's object icon, then the convoy crate as last resort.
        $lotIcon = '/img/iron-armada/auction/crate_convoy.webp';
        if ($lotKey !== null) {
            if (is_file(public_path('img/iron-armada/auction/' . $lotKey . '.webp'))) {
                $lotIcon = '/img/iron-armada/auction/' . $lotKey . '.webp';
            } else {
                $firstUnit = array_key_first($lotUnits);
                if ($firstUnit !== null && is_file(public_path('img/iron-armada/obj/' . $firstUnit . '.webp'))) {
                    $lotIcon = '/img/iron-armada/obj/' . $firstUnit . '.webp';
                }
            }
        }

        $minNext = (int)($auction['minimum_next_bid'] ?? 0);
        $currentBid = (int)($auction['current_bid'] ?? 0);
        $quickMin = $minNext;
        $quick10 = max($minNext, $roundUp10((int)ceil($currentBid * 1.10)));
        $quick25 = max($minNext, $roundUp10((int)ceil($currentBid * 1.25)));
        $extensionCount = (int)($auction['extension_count'] ?? 0);
        $youLead = (bool)($auction['you_lead'] ?? false);
        $leaderName = $auction['leader_name'] ?? null;
        $endsAt = (int)($auction['ends_at'] ?? 0);
    @endphp

    @if (session('auction_success'))
        <div class="alert alert-success ia-auction-alert" id="ia-auction-success">
            {{ session('auction_success') }}
        </div>
    @endif
    @if (session('auction_error'))
        <div class="alert alert-danger ia-auction-alert" id="ia-auction-error">
            {{ session('auction_error') }}
        </div>
    @endif

    <div id="eventboxContent" style="display: none">
        <img height="16" width="16" src="/img/icons/3f9884806436537bdec305aa26fc60.gif">
    </div>
    <div id="traderOverviewcomponent" class="maincontent ia-auction">
        <div id="traderOverview">
            <div id="inhalt">
                <div id="planet" style="background-image: url('/img/iron-armada/auction/auction.jpg'); background-position: 0px 0px; height: 250px;" class="detail">
                    <div id="detail" class="detail_screen small">
                        <div id="techDetailLoading"></div>
                    </div>
                    <div id="loadingOverlay" style="display: none;">
                        <img src="/img/icons/4161a64a933a5345d00cb9fdaa25c7.gif" alt="load...">
                    </div>
                    <div id="header_text" style="display: block;">
                        <h2>{{ __('t_merchant.auction.title') }}</h2>
                        <a class="back_to_overview js_backToOverview tooltip js_hideTipOnMobile right" href="{{ route('merchant.index') }}" data-tooltip-title="{{ __('t_merchant.back') }}" style="display: inline;"></a>
                        <a class="small_back_to_overview js_backToOverview tooltip js_hideTipOnMobile" href="{{ route('merchant.index') }}" data-tooltip-title="{{ __('t_merchant.back') }}"></a>
                    </div>
                </div>
                <div class="c-left c-small"></div>
                <div class="c-right c-small"></div>

                <!-- Armada Salvage Auction Start -->
                <div id="div_traderAuction" class="div_trader">
                    <div class="header">
                        <h2>{{ __('t_merchant.auction.title') }}</h2>
                    </div>
                    <div class="content">
                        <p class="stimulus clearfix ia-auction-intro" id="ia-auction-intro">
                            {{ __('t_merchant.auction.intro') }}
                        </p>

                        {{-- Disabled state: shown only when auction_enabled is 0. --}}
                        <div id="ia-auction-disabled" class="ia-auction-disabled" @if ($enabled) hidden @endif>
                            <p>{{ __('t_merchant.auction.disabled') }}</p>
                        </div>

                        {{-- Between auctions: shown when enabled and no active auction. --}}
                        <div id="ia-auction-closed" class="ia-auction-closed" @if (!$enabled || $auction !== null) hidden @endif>
                            <p>{{ __('t_merchant.auction.closed') }}</p>
                            <p id="ia-auction-next" class="ia-auction-countdown"
                               @if ($nextOpensAt !== null) data-opens-at="{{ $nextOpensAt }}" @endif
                               data-server-now="{{ $serverNow }}">
                                @if ($nextOpensAt !== null)
                                    {{ __('t_merchant.auction.opens_in', ['time' => $formatRemaining($nextOpensAt - $serverNow)]) }}
                                @endif
                            </p>
                        </div>

                        {{-- Live auction: shown when an auction is active. --}}
                        <div id="ia-auction-live" class="ia-auction-live" data-auction-id="{{ $auction['id'] ?? '' }}" @if ($auction === null) hidden @endif>

                            <div class="ia-auction-lot" id="ia-auction-lot">
                                <img class="ia-auction-lot-icon" id="ia-auction-lot-icon" src="{{ $lotIcon }}" alt="{{ $lotName }}" width="96" height="96">
                                <div class="ia-auction-lot-body">
                                    <h3 class="ia-auction-lot-name" id="ia-auction-lot-name">{{ $lotName }}</h3>
                                    <ul class="ia-auction-lot-contents" id="ia-auction-lot-contents">
                                        @foreach ($lotResources as $resourceKey => $resourceAmount)
                                            @if ((int)$resourceAmount > 0)
                                                <li>{{ number_format((int)$resourceAmount, 0, '.', ',') }} {{ __('t_merchant.' . $resourceKey) }}</li>
                                            @endif
                                        @endforeach
                                        @foreach ($lotUnits as $unitKey => $unitCount)
                                            <li>{{ number_format((int)$unitCount, 0, '.', ',') }} &times; {{ \Illuminate\Support\Facades\Lang::has('t_resources.' . $unitKey . '.title') ? __('t_resources.' . $unitKey . '.title') : $unitKey }}</li>
                                        @endforeach
                                    </ul>
                                    <p class="ia-auction-lot-note">{{ __('t_merchant.auction.storage_bypass_note') }}</p>
                                </div>
                            </div>

                            <div class="ia-auction-status" id="ia-auction-status">
                                <dl class="ia-auction-status-list">
                                    <dt>{{ __('t_merchant.auction.current_bid') }}</dt>
                                    <dd id="ia-auction-current-bid">{{ number_format($currentBid, 0, '.', ',') }} {{ __('t_merchant.dark_matter') }}</dd>

                                    <dt>{{ __('t_merchant.auction.leader') }}</dt>
                                    <dd id="ia-auction-leader">
                                        {{ $leaderName !== null ? $leaderName : __('t_merchant.auction.no_bids') }}
                                    </dd>

                                    <dt>{{ __('t_merchant.auction.starting_bid') }}</dt>
                                    <dd id="ia-auction-starting-bid">{{ number_format((int)($auction['starting_bid'] ?? 0), 0, '.', ',') }} {{ __('t_merchant.dark_matter') }}</dd>

                                    <dt>&nbsp;</dt>
                                    <dd id="ia-auction-bid-count">{{ __('t_merchant.auction.bid_count', ['count' => (int)($auction['bid_count'] ?? 0)]) }}</dd>
                                </dl>

                                <span class="ia-auction-chip ia-auction-chip-leading" id="ia-auction-chip-leading" @if (!$youLead) hidden @endif>
                                    {{ __('t_merchant.auction.you_lead') }}
                                </span>
                                <span class="ia-auction-chip ia-auction-chip-extended" id="ia-auction-chip-extended" @if ($extensionCount === 0) hidden @endif>
                                    {{ __('t_merchant.auction.extended') }}
                                </span>

                                <div class="ia-auction-countdown" id="ia-auction-countdown"
                                     data-ends-at="{{ $endsAt }}" data-server-now="{{ $serverNow }}">
                                    {{ __('t_merchant.auction.ends_in', ['time' => $formatRemaining($endsAt - $serverNow)]) }}
                                </div>
                            </div>

                            <form method="POST" action="{{ route('merchant.auction.bid') }}" id="ia-auction-bid-form" class="ia-auction-bidform">
                                @csrf
                                <input type="hidden" name="auction_id" id="ia-auction-id" value="{{ $auction['id'] ?? '' }}">

                                <p class="ia-auction-balance" id="ia-auction-balance">
                                    {{ __('t_merchant.auction.your_balance', ['amount' => number_format((int)$darkMatter, 0, '.', ',')]) }}
                                </p>
                                <p class="ia-auction-minimum" id="ia-auction-minimum">
                                    {{ __('t_merchant.auction.minimum_bid', ['amount' => number_format($minNext, 0, '.', ',')]) }}
                                </p>

                                <div class="ia-auction-quick">
                                    <button type="button" class="ia-auction-quick-btn" id="ia-auction-quick-min" data-amount="{{ $quickMin }}">{{ __('t_merchant.auction.quick_min') }}</button>
                                    <button type="button" class="ia-auction-quick-btn" id="ia-auction-quick-10" data-amount="{{ $quick10 }}">{{ __('t_merchant.auction.quick_10') }}</button>
                                    <button type="button" class="ia-auction-quick-btn" id="ia-auction-quick-25" data-amount="{{ $quick25 }}">{{ __('t_merchant.auction.quick_25') }}</button>
                                </div>

                                <div class="ia-auction-bid-row">
                                    <input type="number" name="amount" id="ia-auction-amount" class="textinput ia-auction-amount"
                                           min="{{ $minNext }}" step="1" value="{{ $minNext }}" inputmode="numeric"
                                           aria-label="{{ __('t_merchant.auction.place_bid') }}">
                                    <button type="submit" class="btn_blue ia-auction-submit" id="ia-auction-submit">{{ __('t_merchant.auction.place_bid') }}</button>
                                </div>

                                <p class="ia-auction-message" id="ia-auction-message" role="status" aria-live="polite"></p>
                                <p class="ia-auction-flash" id="ia-auction-flash" role="status" hidden>{{ __('t_merchant.auction.outbid_flash') }}</p>
                            </form>

                            <div class="ia-auction-feed" id="ia-auction-feed">
                                <h3>{{ __('t_merchant.auction.recent_bids') }}</h3>
                                <ol class="ia-auction-feed-list" id="ia-auction-feed-list">
                                    @foreach ($recentBids as $bid)
                                        <li>
                                            <span class="ia-auction-feed-name">{{ $bid['name'] ?? '' }}</span>
                                            <span class="ia-auction-feed-amount">{{ number_format((int)$bid['amount'], 0, '.', ',') }}</span>
                                            <time class="ia-auction-feed-time">{{ gmdate('H:i', (int)$bid['placed_at']) }}</time>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </div>

                        {{-- Unclaimed wins: one claim form per lot. Shown only when there is something to claim. --}}
                        @if (!empty($unclaimed))
                            @php
                                $currentPlanetId = resolve(\OGame\Services\PlayerService::class)->planets->current()->getPlanetId();
                            @endphp
                            <div id="ia-auction-unclaimed" class="ia-auction-unclaimed">
                                <h3>{{ __('t_merchant.auction.unclaimed_title') }}</h3>
                                @foreach ($unclaimed as $won)
                                    <form method="POST" action="{{ route('merchant.auction.claim') }}" class="ia-auction-claim-row">
                                        @csrf
                                        <input type="hidden" name="auction_id" value="{{ $won['auction_id'] }}">
                                        <span class="ia-auction-claim-lot">{{ __('t_merchant.auction.lots.' . $won['lot_key']) }}</span>
                                        <label class="ia-auction-claim-label" for="ia-auction-claim-planet-{{ $won['auction_id'] }}">{{ __('t_merchant.auction.claim_to_planet') }}</label>
                                        <select name="planet_id" id="ia-auction-claim-planet-{{ $won['auction_id'] }}" class="ia-auction-claim-planet">
                                            @foreach ($planets as $planetOption)
                                                <option value="{{ $planetOption['id'] }}" @selected($planetOption['id'] === $currentPlanetId)>{{ $planetOption['name'] }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn_blue ia-auction-claim-btn">{{ __('t_merchant.auction.claim') }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @endif

                        <div id="ia-auction-history" class="ia-auction-history">
                            <h3>{{ __('t_merchant.auction.history') }}</h3>
                            <ul class="ia-auction-history-list" id="ia-auction-history-list">
                                @foreach ($history as $row)
                                    <li class="ia-auction-history-row">
                                        <span class="ia-auction-history-lot">{{ __('t_merchant.auction.lots.' . $row->lot_key) }}</span>
                                        <span class="ia-auction-history-winner">{{ $row->winner_user_id !== null ? ($row->winner?->username ?? '') : '' }}</span>
                                        <span class="ia-auction-history-price">
                                            @if ($row->winner_user_id !== null && $row->final_price !== null)
                                                {{ number_format((int)$row->final_price, 0, '.', ',') }}
                                            @else
                                                {{ __('t_merchant.auction.history_unsold') }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
                <!-- Armada Salvage Auction End -->
            </div>
        </div>
    </div>

@endsection
