@extends('outgame.layouts.main')

@section('content')
@php
    $registerActive = $errors->register->any();
@endphp

<section class="ia-hero">
    <div class="ia-hero-copy">
        <h1>{{ __('t_external.home.hero_title') }}</h1>
        <p>{{ __('t_external.home.hero_text') }}</p>
    </div>

    <div class="ia-plate" id="ia-plate">
        <div class="ia-plate-inner">
            <div class="ia-tabs" role="tablist" aria-label="{{ __('t_external.home.tabs_label') }}">
                <button type="button" role="tab" id="ia-tab-login" aria-controls="ia-panel-login" aria-selected="{{ $registerActive ? 'false' : 'true' }}">{{ __('t_external.home.tab_login') }}</button>
                <button type="button" role="tab" id="ia-tab-register" aria-controls="ia-panel-register" aria-selected="{{ $registerActive ? 'true' : 'false' }}">{{ __('t_external.home.tab_register') }}</button>
            </div>

            <div class="ia-panel" role="tabpanel" id="ia-panel-login" aria-labelledby="ia-tab-login" @if($registerActive) hidden @endif>
                <form id="iaLoginForm" name="loginForm" method="post" action="{{ route('login') }}">
                    {{ csrf_field() }}

                    @if (session('ban_message'))
                        <p class="ia-alert" role="alert">{{ session('ban_message') }}</p>
                    @endif
                    @if ($errors->has('email'))
                        <p class="ia-alert" role="alert">{{ $errors->first('email') }}</p>
                    @endif
                    @if ($errors->has('password'))
                        <p class="ia-alert" role="alert">{{ $errors->first('password') }}</p>
                    @endif

                    <div class="ia-field">
                        <label for="iaLoginEmail">{{ __('t_external.home.field_email') }}</label>
                        <input type="email" id="iaLoginEmail" name="email" value="{{ old('email') }}" autocomplete="email" required/>
                    </div>
                    <div class="ia-field">
                        <label for="iaLoginPassword">{{ __('t_external.home.field_password') }}</label>
                        <input type="password" id="iaLoginPassword" name="password" maxlength="128" autocomplete="current-password" required/>
                    </div>
                    <div class="ia-field">
                        <label for="iaLoginUniverse">{{ __('t_external.home.field_universe') }}</label>
                        <select id="iaLoginUniverse" name="uni">
                            <option value="s1">{{ __('t_external.login.universe_option_1') }}</option>
                        </select>
                    </div>
                    <button type="submit" class="ia-submit">{{ __('t_external.home.login_submit') }}</button>
                </form>
            </div>

            <div class="ia-panel" role="tabpanel" id="ia-panel-register" aria-labelledby="ia-tab-register" @if(! $registerActive) hidden @endif>
                <form id="iaRegisterForm" name="subscribeForm" method="POST" action="{{ route('register') }}" autocomplete="off">
                    {{ csrf_field() }}
                    <input type="hidden" name="v" value="3"/>
                    <input type="hidden" name="step" value="validate"/>
                    <input type="hidden" name="kid" value=""/>
                    <input type="hidden" name="errorCodeOn" value="1"/>
                    <input type="hidden" name="is_utf8" value="1"/>
                    <input type="hidden" name="agb" value="on"/>

                    @if ($errors->register->has('email'))
                        <p class="ia-alert" role="alert">{{ $errors->register->first('email') }}</p>
                    @endif
                    @if ($errors->register->has('password'))
                        <p class="ia-alert" role="alert">{{ $errors->register->first('password') }}</p>
                    @endif

                    <div class="ia-field">
                        <label for="iaRegEmail">{{ __('t_external.home.field_email') }}</label>
                        <input type="email" id="iaRegEmail" name="email" value="{{ old('email') }}" autocomplete="email" required/>
                    </div>
                    <div class="ia-field">
                        <label for="iaRegPassword">{{ __('t_external.home.field_password') }}</label>
                        <input type="password" id="iaRegPassword" name="password" minlength="8" maxlength="128" autocomplete="new-password" aria-describedby="iaRegHint" required/>
                        <span class="ia-hint" id="iaRegHint">{{ __('t_external.home.password_hint') }}</span>
                    </div>
                    <button type="submit" class="ia-submit">{{ __('t_external.home.register_submit') }}</button>
                    <p class="ia-terms">{!! __('t_external.home.register_terms_html') !!}</p>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="ia-loop" aria-label="{{ __('t_external.home.loop_label') }}">
    <div class="ia-loop-inner">
        @foreach (['mine', 'research', 'fight'] as $step)
            <div class="ia-loop-item">
                <h2>{{ __('t_external.home.loop.' . $step . '.title') }}</h2>
                <p>{{ __('t_external.home.loop.' . $step . '.text') }}</p>
            </div>
        @endforeach
    </div>
</section>

<script>
    (function () {
        var tabs = document.querySelectorAll('.ia-tabs [role="tab"]');
        function show(id) {
            tabs.forEach(function (tab) {
                var on = tab.id === id;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                var panel = document.getElementById(tab.getAttribute('aria-controls'));
                if (panel) { panel.hidden = !on; }
            });
        }
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                show(tab.id);
                var first = document.querySelector('#' + tab.getAttribute('aria-controls') + ' input:not([type=hidden])');
                if (first) { first.focus(); }
            });
            tab.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
                var next = tabs[(Array.prototype.indexOf.call(tabs, tab) + 1) % tabs.length];
                show(next.id);
                next.focus();
            });
        });
        if (location.hash === '#register') { show('ia-tab-register'); }
    })();
</script>
@endsection
