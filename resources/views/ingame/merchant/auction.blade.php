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

    {{-- Strings for the polling script (T12). Raw templates keep their :placeholders; the script fills them in. --}}
    <div id="ia-auction-i18n" hidden
         data-state-url="{{ route('merchant.auction.state') }}"
         data-ends-in="{{ __('t_merchant.auction.ends_in') }}"
         data-opens-in="{{ __('t_merchant.auction.opens_in') }}"
         data-bid-count="{{ __('t_merchant.auction.bid_count') }}"
         data-your-balance="{{ __('t_merchant.auction.your_balance') }}"
         data-minimum-bid="{{ __('t_merchant.auction.minimum_bid') }}"
         data-dark-matter="{{ __('t_merchant.dark_matter') }}"
         data-no-bids="{{ __('t_merchant.auction.no_bids') }}"
         data-badge-current="{{ __('t_merchant.auction.badge_current') }}"
         data-badge-closed="{{ __('t_merchant.auction.badge_closed') }}"
         data-try-again="{{ __('t_merchant.auction.error.try_again') }}"></div>

    <script type="text/javascript">
        $(document).ready(function() {
            var $i18n = $('#ia-auction-i18n');
            var $form = $('#ia-auction-bid-form');
            if (!$i18n.length || !$form.length) {
                return;
            }

            function str(name) {
                return $i18n.attr('data-' + name) || '';
            }

            // Fills a ":name" placeholder without treating the replacement as a regex pattern.
            function fill(template, name, value) {
                return template.split(':' + name).join(String(value));
            }

            function pad2(n) {
                return (n < 10 ? '0' : '') + n;
            }

            function formatNumber(n) {
                n = Math.floor(Number(n) || 0);
                return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            // Same shape as the server-rendered first paint ($formatRemaining in the blade).
            function formatRemaining(seconds) {
                seconds = Math.max(0, Math.floor(seconds));
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                var secs = seconds % 60;
                if (hours > 0) {
                    return hours + 'h ' + minutes + 'm';
                }
                if (minutes > 0) {
                    return minutes + 'm ' + secs + 's';
                }
                return secs + 's';
            }

            function roundUp10(value) {
                return Math.floor((value + 9) / 10) * 10;
            }

            // Mirrors the quick-button amounts rendered by the blade.
            function quickAmounts(minNext, currentBid) {
                return {
                    min: minNext,
                    p10: Math.max(minNext, roundUp10(Math.ceil(currentBid * 1.10))),
                    p25: Math.max(minNext, roundUp10(Math.ceil(currentBid * 1.25)))
                };
            }

            var POLL_SLOW = 10000;
            var POLL_FAST = 3000;
            var FAST_WINDOW = 300; // seconds before ends_at
            var FLASH_MS = 15000;

            var $live = $('#ia-auction-live');
            var $closed = $('#ia-auction-closed');
            var $disabled = $('#ia-auction-disabled');
            var $countdown = $('#ia-auction-countdown');
            var $next = $('#ia-auction-next');
            var $amount = $('#ia-auction-amount');
            var $submit = $('#ia-auction-submit');
            var $message = $('#ia-auction-message');
            var $flash = $('#ia-auction-flash');

            var renderedAuctionId = parseInt($live.attr('data-auction-id'), 10) || 0;
            var renderedUnclaimed = $('#ia-auction-unclaimed .ia-auction-claim-row').length;

            var endsAt = parseInt($countdown.attr('data-ends-at'), 10) || 0;
            var opensAt = parseInt($next.attr('data-opens-at'), 10) || 0;
            var clockOffsetMs = 0;      // server clock minus client clock, refreshed on every response
            var lastServerNow = 0;
            var auctionActive = renderedAuctionId > 0 && !$live.prop('hidden');
            var stopped = false;        // polling stopped (closed or disabled)
            var wasLeading = $('#ia-auction-chip-leading').prop('hidden') === false;
            var amountDirty = false;
            var inFlight = false;       // bid POST in flight
            var pollXhr = null;
            var pollTimer = null;
            var tickTimer = null;
            var flashTimer = null;
            var epoch = 0;              // bumped by bid responses so older poll responses are dropped
            var failures = 0;
            var closeKicked = false;

            function setClock(serverNow) {
                serverNow = parseInt(serverNow, 10);
                if (serverNow > 0) {
                    clockOffsetMs = serverNow * 1000 - new Date().getTime();
                    lastServerNow = serverNow;
                }
            }

            // Current server time in whole seconds: client clock plus the offset taken from the last response.
            function serverNowSec() {
                return Math.floor((new Date().getTime() + clockOffsetMs) / 1000);
            }

            setClock($countdown.attr('data-server-now') || $next.attr('data-server-now'));

            function remainingSeconds() {
                return endsAt - serverNowSec();
            }

            function renderCountdown() {
                if (auctionActive) {
                    var left = remainingSeconds();
                    $countdown.text(fill(str('ends-in'), 'time', formatRemaining(left)));
                    $countdown.toggleClass('ia-auction-countdown-urgent is-final', left < FAST_WINDOW);
                    if (left <= 0 && !closeKicked && !stopped) {
                        // Hammer time: ask the server straight away instead of waiting for the next poll.
                        closeKicked = true;
                        pollNow();
                    }
                } else if (opensAt > 0 && $next.length) {
                    var untilOpen = opensAt - serverNowSec();
                    $next.text(fill(str('opens-in'), 'time', formatRemaining(untilOpen)));
                    if (stopped && untilOpen <= 0) {
                        // The next auction should be open about now: resume polling to pick it up.
                        opensAt = 0;
                        stopped = false;
                        pollNow();
                    }
                }
            }

            function startTick() {
                if (tickTimer === null) {
                    tickTimer = window.setInterval(renderCountdown, 1000);
                }
            }

            function stopTick() {
                if (tickTimer !== null) {
                    window.clearInterval(tickTimer);
                    tickTimer = null;
                }
            }

            function showMessage(text, isError) {
                $message.text(text || '');
                $message.toggleClass('ia-auction-message-error', !!isError);
            }

            function hideFlash() {
                $flash.prop('hidden', true);
                if (flashTimer !== null) {
                    window.clearTimeout(flashTimer);
                    flashTimer = null;
                }
            }

            function showFlash() {
                $flash.prop('hidden', false);
                if (flashTimer !== null) {
                    window.clearTimeout(flashTimer);
                }
                flashTimer = window.setTimeout(hideFlash, FLASH_MS);
            }

            function renderFeed(bids) {
                var $list = $('#ia-auction-feed-list');
                $list.empty();
                $.each(bids || [], function(i, bid) {
                    var d = new Date((parseInt(bid.placed_at, 10) || 0) * 1000);
                    var $li = $('<li></li>');
                    $('<span class="ia-auction-feed-name"></span>').text(bid.name === null || bid.name === undefined ? '' : String(bid.name)).appendTo($li);
                    $('<span class="ia-auction-feed-amount"></span>').text(formatNumber(bid.amount)).appendTo($li);
                    $('<time class="ia-auction-feed-time"></time>').text(pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes())).appendTo($li);
                    $list.append($li);
                });
            }

            function renderBadge(auction) {
                var $badge = $('#ia-auction-badge');
                if (!$badge.length) {
                    return; // the badge lives on the merchant overview tile, not on this page
                }
                if (!auction) {
                    $badge.text(str('badge-closed'));
                } else if ((parseInt(auction.current_bid, 10) || 0) > 0) {
                    $badge.text(formatNumber(auction.current_bid));
                } else {
                    $badge.text(str('badge-current'));
                }
            }

            function renderQuick(minNext, currentBid) {
                var q = quickAmounts(minNext, currentBid);
                $('#ia-auction-quick-min').attr('data-amount', q.min);
                $('#ia-auction-quick-10').attr('data-amount', q.p10);
                $('#ia-auction-quick-25').attr('data-amount', q.p25);
            }

            function showClosed(state) {
                auctionActive = false;
                $live.prop('hidden', true);
                $disabled.prop('hidden', true);
                $closed.prop('hidden', false);
                opensAt = parseInt(state.next_opens_at, 10) || 0;
                $next.attr('data-opens-at', opensAt);
                hideFlash();
                $submit.prop('disabled', true);
                stopped = true;
                clearPoll();
                renderCountdown();
            }

            function showDisabled() {
                auctionActive = false;
                $live.prop('hidden', true);
                $closed.prop('hidden', true);
                $disabled.prop('hidden', false);
                $submit.prop('disabled', true);
                stopped = true;
                clearPoll();
            }

            // Applies a state payload (from a poll or from a bid response) to the page.
            function applyState(state) {
                if (!state || typeof state !== 'object') {
                    return;
                }
                if (parseInt(state.server_now, 10) < lastServerNow) {
                    return; // older than what is already shown
                }
                setClock(state.server_now);

                if (state.enabled === false) {
                    showDisabled();
                    renderBadge(null);
                    return;
                }

                var auction = state.auction;
                var unclaimed = $.isArray(state.unclaimed) ? state.unclaimed.length : 0;

                if (!auction || auction.status !== 'active') {
                    var wasActive = auctionActive;
                    renderBadge(null);
                    showClosed(state);
                    if (wasActive && unclaimed !== renderedUnclaimed) {
                        // A win to claim: reload so the claim form and history are rendered.
                        window.setTimeout(function() { window.location.reload(); }, 2500);
                    }
                    return;
                }

                if (auction.id !== renderedAuctionId) {
                    // A different lot than the one rendered: lot name, icon and contents need the full page.
                    window.location.reload();
                    return;
                }

                auctionActive = true;
                endsAt = parseInt(auction.ends_at, 10) || 0;
                closeKicked = false;
                $closed.prop('hidden', true);
                $disabled.prop('hidden', true);
                $live.prop('hidden', false);

                var currentBid = parseInt(auction.current_bid, 10) || 0;
                var minNext = parseInt(auction.minimum_next_bid, 10) || 0;
                var youLead = auction.you_lead === true;

                $('#ia-auction-current-bid').text(formatNumber(currentBid) + ' ' + str('dark-matter'));
                $('#ia-auction-leader').text(auction.leader_name ? String(auction.leader_name) : str('no-bids'));
                $('#ia-auction-bid-count').text(fill(str('bid-count'), 'count', parseInt(auction.bid_count, 10) || 0));
                $('#ia-auction-balance').text(fill(str('your-balance'), 'amount', formatNumber(state.dark_matter)));
                $('#ia-auction-minimum').text(fill(str('minimum-bid'), 'amount', formatNumber(minNext)));
                $('#ia-auction-chip-leading').prop('hidden', !youLead);
                $('#ia-auction-chip-extended').prop('hidden', !((parseInt(auction.extension_count, 10) || 0) > 0));

                renderFeed(state.recent_bids);
                renderBadge(auction);
                renderQuick(minNext, currentBid);

                $amount.attr('min', minNext);
                var typed = parseInt(String($amount.val()).replace(/[^0-9]/g, ''), 10);
                if (!amountDirty || isNaN(typed) || typed < minNext) {
                    $amount.val(minNext);
                }

                if (!inFlight) {
                    $submit.prop('disabled', false);
                }

                if (wasLeading && !youLead) {
                    showFlash();
                } else if (youLead) {
                    hideFlash();
                }
                wasLeading = youLead;

                renderCountdown();
            }

            // ----- Polling -----

            function clearPoll() {
                if (pollTimer !== null) {
                    window.clearTimeout(pollTimer);
                    pollTimer = null;
                }
            }

            function nextDelay() {
                var base = (auctionActive && remainingSeconds() < FAST_WINDOW) ? POLL_FAST : POLL_SLOW;
                return Math.min(30000, base * (1 + failures));
            }

            function schedulePoll() {
                clearPoll();
                if (stopped || document.hidden) {
                    return;
                }
                pollTimer = window.setTimeout(poll, nextDelay());
            }

            function poll() {
                clearPoll();
                if (stopped || document.hidden) {
                    return;
                }
                if (pollXhr !== null || inFlight) {
                    schedulePoll();
                    return;
                }
                var startEpoch = epoch;
                pollXhr = $.ajax({
                    url: str('state-url'),
                    type: 'GET',
                    dataType: 'json',
                    cache: false
                }).done(function(state) {
                    failures = 0;
                    if (startEpoch === epoch) {
                        applyState(state);
                    }
                }).fail(function() {
                    failures = Math.min(failures + 1, 5);
                }).always(function() {
                    pollXhr = null;
                    schedulePoll();
                });
            }

            function pollNow() {
                clearPoll();
                poll();
            }

            // ----- Bidding -----

            $('.ia-auction-quick-btn').on('click', function() {
                var amount = parseInt($(this).attr('data-amount'), 10);
                if (amount > 0) {
                    $amount.val(amount);
                    amountDirty = true;
                }
            });

            $amount.on('input change', function() {
                amountDirty = true;
            });

            $form.on('submit', function(e) {
                e.preventDefault();
                if (inFlight || !auctionActive) {
                    return;
                }
                var amount = parseInt(String($amount.val()).replace(/[^0-9]/g, ''), 10);
                if (isNaN(amount) || amount < 1) {
                    var minNext = parseInt($amount.attr('min'), 10) || 0;
                    showMessage(fill(str('minimum-bid'), 'amount', formatNumber(minNext)), true);
                    return;
                }

                inFlight = true;
                $submit.prop('disabled', true);
                showMessage('', false);
                hideFlash();

                $.ajax({
                    url: $form.attr('action'),
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: $form.find('input[name="_token"]').val(),
                        auction_id: $('#ia-auction-id').val(),
                        amount: amount
                    }
                }).done(function(data) {
                    epoch++;
                    if (!data || typeof data !== 'object') {
                        showMessage(str('try-again'), true);
                        return;
                    }
                    if (data.success === true) {
                        amountDirty = false;
                        showMessage(data.message, false);
                    } else if (data.message === 'Invalid token.') {
                        // Session token no longer matches; the server sent no state.
                        showMessage(str('try-again'), true);
                    } else {
                        showMessage(data.message || str('try-again'), true);
                    }
                    if (data.state) {
                        // Applied for failures too, so a lost lead or a closed auction shows immediately.
                        inFlight = false;
                        applyState(data.state);
                    }
                }).fail(function(xhr) {
                    epoch++;
                    var msg = '';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = String(xhr.responseJSON.message); // e.g. 422 validation text
                    }
                    showMessage(msg || str('try-again'), true);
                }).always(function() {
                    inFlight = false;
                    if (auctionActive && !stopped) {
                        $submit.prop('disabled', false);
                    }
                    schedulePoll();
                });
            });

            // ----- Visibility and start-up -----

            $(document).on('visibilitychange', function() {
                if (document.hidden) {
                    clearPoll();
                    stopTick();
                } else {
                    startTick();
                    renderCountdown();
                    if (!stopped) {
                        pollNow();
                    }
                }
            });

            if (!$disabled.prop('hidden')) {
                stopped = true; // auctions disabled: nothing to poll
            }
            renderCountdown();
            if (!document.hidden) {
                startTick();
            }
            if (!stopped) {
                schedulePoll();
            }
        });
    </script>

@endsection
