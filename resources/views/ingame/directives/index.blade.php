@extends('ingame.layouts.main')

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div id="directivescomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" style="background-image:url(/img/headers/rewards/rewards.jpg);height:250px;">
                    <div id="header_text">
                        <h2>{{ __('t_ingame.directives.title') }}</h2>
                    </div>
                </div>

                <div id="buttonz">
                    <div class="header"><h2>{{ __('t_ingame.directives.title') }}</h2></div>
                    <div class="content">
                        <p class="ia-directives-intro">{{ __('t_ingame.directives.intro') }}
                            <span class="ia-directives-balance">{{ __('t_ingame.directives.balance') }}: <b>{{ number_format($darkMatter) }}</b></span>
                        </p>

                        @foreach (['daily', 'weekly'] as $period)
                            <h3 class="ia-directives-heading">{{ __('t_ingame.directives.' . $period) }}</h3>
                            <div class="ia-directives">
                                @foreach ($directives as $directive)
                                    @if ($directive['period'] === $period)
                                        @php
                                            $percent = (int)floor($directive['progress'] / $directive['target'] * 100);
                                            $hoursLeft = max(0, (int)ceil(($directive['resets_at'] - time()) / 3600));
                                        @endphp
                                        <div class="ia-directive {{ $directive['claimed'] ? 'is-claimed' : ($directive['complete'] ? 'is-complete' : '') }}">
                                            <div class="ia-directive-text">
                                                <div class="ia-directive-name">{{ __('t_ingame.directives.' . $directive['key'], ['target' => $directive['target']]) }}</div>
                                                <div class="ia-directive-bar"><span style="width: {{ $percent }}%"></span></div>
                                                <div class="ia-directive-meta">
                                                    {{ $directive['progress'] }} / {{ $directive['target'] }}
                                                    &middot; {{ __('t_ingame.directives.resets_in', ['hours' => $hoursLeft]) }}
                                                </div>
                                            </div>
                                            <div class="ia-directive-reward">
                                                <div class="ia-directive-dm">+{{ number_format($directive['reward']) }} <small>DM</small></div>
                                                @if ($directive['claimed'])
                                                    <span class="ia-directive-state">{{ __('t_ingame.directives.state_claimed') }}</span>
                                                @elseif ($directive['complete'])
                                                    <form method="post" action="{{ route('directives.claim') }}">
                                                        {{ csrf_field() }}
                                                        <input type="hidden" name="directive" value="{{ $directive['key'] }}">
                                                        <input type="submit" class="btn_blue" value="{{ __('t_ingame.directives.claim') }}">
                                                    </form>
                                                @else
                                                    <span class="ia-directive-state">{{ __('t_ingame.directives.state_open') }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div class="footer"></div>
                </div>
            </div>
        </div>
    </div>
@endsection
