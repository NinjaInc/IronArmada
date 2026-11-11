<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN""http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html lang="{{ app()->getLocale() }}">
<head>
    <!--
     ===========================================
       ____   _____                     __   __
      / __ \ / ____|                    \ \ / /
     | |  | | |  __  __ _ _ __ ___   ___ \ V /
     | |  | | | |_ |/ _` | '_ ` _ \ / _ \ > <
     | |__| | |__| | (_| | | | | | |  __// . \
      \____/ \_____|\__,_|_| |_| |_|\___/_/ \_\
     ===========================================

     Powered by OGameX - Explore the universe! Conquer your enemies!
     GitHub: https://github.com/lanedirt/OGameX
     Version: {{ \OGame\Facades\GitInfoUtil::getAppVersionBranchCommit() }}

    This application is released under the MIT License. For more details, visit the GitHub repository.
-->
    <!-- CSRF Token -->
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta name="language" content="{{ app()->getLocale() }}"/>
    <meta name="author" content="Archon Tech"/>
    <meta name="publisher" content="Archon Tech"/>
    <meta name="copyright" content="Archon Tech"/>
    <meta name="audience" content="all"/>
    <meta name="Expires" content="never"/>
    <meta name="Keywords"
          content="Game, Browser, online, strategy, Science fiction, space, space ship, Iron Armada"/>
    <meta name="Description"
          content="Iron Armada - a private space strategy game by Archon Tech. Build, research, raise a fleet and conquer the universe."/>
    <meta name="robots" content="index,follow"/>
    <meta name="Revisit" content="After 14 days"/>
    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="icon" type="image/png" sizes="32x32" href="/img/iron-armada/favicon-32.png"/>
    <link rel="icon" href="/favicon.ico" sizes="any"/>
    <link rel="apple-touch-icon" href="/img/iron-armada/icon-180.png"/>
    @vite(['resources/css/outgame.css', 'resources/js/outgame.js'])

    <script type="text/javascript">
        // <![CDATA[
        (function ($) {
            $.fn.validationEngineLanguage = function () {
            };
            $.validationEngineLanguage = {
                newLang: function () {
                    $.validationEngineLanguage.allRules = {
                        "required": {
                            "alertText": {!! json_encode(__('t_external.validation.required')) !!},
                            "alertTextCheckboxMultiple": {!! json_encode(__('t_external.validation.make_decision')) !!},
                            "alertTextCheckboxe": {!! json_encode(__('t_external.validation.accept_terms')) !!}
                        },
                        "length": {
                            "regex": /^.{3,20}$/,
                            "alertText": {!! json_encode(__('t_external.validation.length')) !!}
                        },
                        "pwLength": {
                            "regex": /^.{4,20}$/,
                            "alertText": {!! json_encode(__('t_external.validation.pw_length')) !!}
                        },
                        "email": {
                            "regex": /^[a-zA-Z0-9_.\-]+@([a-zA-Z0-9\-]+\.)+[a-zA-Z0-9]{2,4}$/,
                            "alertText": {!! json_encode(__('t_external.validation.email')) !!}
                        },
                        "noSpecialCharacters": {
                            "regex": /^[a-zA-Z0-9\s_\-]+$/,
                            "alertText": {!! json_encode(__('t_external.validation.invalid_chars')) !!}
                        },
                        "noBeginOrEndUnderscore": {
                            "regex": /^([^_]+(.*[^_])?)?$/,
                            "alertText": {!! json_encode(__('t_external.validation.no_begin_end_underscore')) !!}
                        },
                        "noBeginOrEndHyphen": {
                            "regex": /^([^\-]+(.*[^\-])?)?$/,
                            "alertText": ""
                        },
                        "noBeginOrEndWhitespace": {
                            "regex": /^([^\s]+(.*[^\s])?)?$/,
                            "alertText": {!! json_encode(__('t_external.validation.no_begin_end_whitespace')) !!}
                        },
                        "notMoreThanThreeUnderscores": {
                            "regex": /^[^_]*(_[^_]*){0,3}$/,
                            "alertText": {!! json_encode(__('t_external.validation.max_three_underscores')) !!}
                        },
                        "notMoreThanThreeHyphen": {
                            "regex": /^[^\-]*(\-[^\-]*){0,3}$/,
                            "alertText": ""
                        },
                        "notMoreThanThreeWhitespaces": {
                            "regex": /^[^\s]*(\s[^\s]*){0,3}$/,
                            "alertText": {!! json_encode(__('t_external.validation.max_three_whitespaces')) !!}
                        },
                        "noCollocateUnderscores": {
                            "regex": /^[^_]*(_[^_]+)*_?$/,
                            "alertText": {!! json_encode(__('t_external.validation.no_consecutive_underscores')) !!}
                        },
                        "noCollocateHyphen": {
                            "regex": /^[^\-]*(\-[^\-]+)*-?$/,
                            "alertText": ""
                        },
                        "noCollocateWhitespaces": {
                            "regex": /^[^\s]*(\s[^\s]+)*\s?$/,
                            "alertText": {!! json_encode(__('t_external.validation.no_consecutive_whitespaces')) !!}
                        },
                        "ajaxUser": {
                            "file": "../validateUser.php",
                            "alertTextOk": {!! json_encode(__('t_external.validation.username_available')) !!},
                            "alertTextLoad": {!! json_encode(__('t_external.validation.username_loading')) !!},
                            "alertText": {!! json_encode(__('t_external.validation.username_taken')) !!}
                        },
                        "ajaxName": {
                            "file": "../validateUser.php",
                            "alertTextOk": {!! json_encode(__('t_external.validation.username_available')) !!},
                            "alertTextLoad": {!! json_encode(__('t_external.validation.username_available')) !!}
                        },
                        "alertText": {!! json_encode(__('t_external.validation.username_taken')) !!},
                        "onlyLetter": {
                            "regex": /^[a-zA-Z ']+$/,
                            "alertText": {!! json_encode(__('t_external.validation.only_letters')) !!}
                        }
                    }
                }
            }
        })(jQuery);
        var universeDistinctions = [];

        $(document).ready(function () {
            $(".zebra tr:odd").addClass("alt");
            $.validationEngineLanguage.newLang();
        });
        // ]]>
    </script>
    <script type="text/javascript">
        var emailOnlySignup = 1;
        var emailOnlyLogin = 1;
    </script>
</head>
<body class='{{ app()->getLocale() }} ia'>
<a class="ia-skip" href="#ia-main">{{ __('t_external.home.skip_link') }}</a>
<div class="ia-page">
    <header class="ia-header">
        <a class="ia-brand" href="{{ route('login') }}" title="{{ __('t_external.login.page_title') }}">
            <img src="/img/iron-armada/logo-iron-armada.png" alt="Iron Armada" width="260" height="80"/>
        </a>
    </header>

    <main id="ia-main" class="ia-main">
        @yield('content')
    </main>

    <footer class="ia-footer">
        <div class="ia-footer-inner">
            <nav class="ia-footer-links" aria-label="{{ __('t_external.home.footer_nav') }}">
                <a class="overlay" data-type="ajax" href="/ajax/main/legal">{{ __('t_external.footer.legal') }}</a>
                <a class="overlay" data-type="ajax" href="/ajax/main/privacy-policy">{{ __('t_external.footer.privacy_policy') }}</a>
                <a class="overlay" data-type="ajax" href="/ajax/main/terms">{{ __('t_external.footer.terms') }}</a>
                <a class="overlay" data-type="ajax" href="/ajax/main/contact">{{ __('t_external.footer.contact') }}</a>
                <a class="overlay" data-type="ajax" href="/ajax/main/rules">{{ __('t_external.footer.rules') }}</a>
            </nav>
            @php $outgameLocale = app()->getLocale(); @endphp
            <nav class="ia-footer-langs" aria-label="{{ __('t_external.home.language_nav') }}">
                <a href="{{ route('language.switch', ['lang' => 'en']) }}" @if($outgameLocale === 'en') aria-current="true" @endif>EN</a>
                <a href="{{ route('language.switch', ['lang' => 'it']) }}" @if($outgameLocale === 'it') aria-current="true" @endif>IT</a>
                <a href="{{ route('language.switch', ['lang' => 'nl']) }}" @if($outgameLocale === 'nl') aria-current="true" @endif>NL</a>
                <a href="{{ route('language.switch', ['lang' => 'fr']) }}" @if($outgameLocale === 'fr') aria-current="true" @endif>FR</a>
                <a href="{{ route('language.switch', ['lang' => 'zh-TW']) }}" @if($outgameLocale === 'zh-TW') aria-current="true" @endif>繁體中文</a>
            </nav>
            <div class="ia-footer-brand">
                <a class="ia-archon" href="{{ __('t_external.links.archon_tech_url') }}" target="_blank" rel="noopener">
                    <img src="/img/iron-armada/logo-archon-tech.png" alt="{{ __('t_external.links.archon_tech') }}" height="44"/>
                </a>
                <nav class="ia-footer-links" aria-label="Archon Tech">
                    <a href="{{ __('t_external.links.archon_tech_url') }}" target="_blank" rel="noopener">{{ __('t_external.links.archon_tech') }}</a>
                    <a href="{{ __('t_external.links.archon_hosting_url') }}" target="_blank" rel="noopener">{{ __('t_external.links.archon_hosting') }}</a>
                    <a href="{{ __('t_external.links.support_url') }}" target="_blank" rel="noopener">{{ __('t_external.links.support') }}</a>
                    <a href="{{ config('app.discord_invite') }}" target="_blank" rel="noopener">Discord</a>
                </nav>
            </div>
            <p class="ia-copyright">{{ __('t_external.footer.copyright') }} {{ \OGame\Facades\GitInfoUtil::getAppVersion() }}</p>
        </div>
    </footer>
</div>
<!-- OVERLAY DIVISION -->
<script type="text/javascript">
    checkIpadApp();
    JSLoca = new Array({!! json_encode(__('t_external.js.login')) !!}, {!! json_encode(__('t_external.js.close')) !!});

    var global_language = "{{ app()->getLocale() }}";
    var text_age_check_failed = {!! json_encode(__('t_external.js.age_check_failed')) !!};
</script>
<script type="text/javascript">
    $(document).ready(function () {
        ogame.characteristics.init({
            "speed_fleet": {
                "css": "speed_fleet",
                "text": {!! json_encode(__('t_external.universe_characteristics.fleet_speed')) !!},
                "valueCategory": "speed",
                "valueKey": "fleet",
                "valueAppendix": "x",
                "type": "range"
            },
            "speed_economy": {
                "css": "speed_economy",
                "text": {!! json_encode(__('t_external.universe_characteristics.economy_speed')) !!},
                "valueCategory": "speed",
                "valueKey": "server",
                "valueAppendix": "x",
                "type": "range"
            },
            "debris_field_factor_ships": {
                "css": "ships_in_debris_field",
                "text": {!! json_encode(__('t_external.universe_characteristics.debris_ships')) !!},
                "valueCategory": "combat",
                "valueKey": "debris_field_factor_ships",
                "valueAppendix": "%",
                "type": "range",
                "step": 10
            },
            "defence_in_debris_field": {
                "css": "defence_in_debris_field",
                "text": {!! json_encode(__('t_external.universe_characteristics.debris_defence')) !!},
                "valueCategory": "combat",
                "valueKey": "debris_field_factor_def",
                "valueAppendix": "%",
                "type": "range",
                "step": 10
            },
            "dark_matter_signup_gift": {
                "css": "dm",
                "text": {!! json_encode(__('t_external.universe_characteristics.dark_matter_gift')) !!},
                "valueCategory": "general",
                "valueKey": "dark_matter_signup_gift",
                "type": "range",
                "step": 1000
            },
            "aks_on": {
                "css": "aks_on",
                "text": {!! json_encode(__('t_external.universe_characteristics.aks_on')) !!},
                "valueCategory": "alliance",
                "valueKey": "aks",
                "condition": "tooltip.alliance.aks",
                "type": "binary"
            },
            "planet_fields": {
                "css": "planet_fields",
                "text": {!! json_encode(__('t_external.universe_characteristics.planet_fields')) !!},
                "valueCategory": "size",
                "valueKey": "planet_field_bonus",
                "type": "range",
                "step": 10
            },
            "wreckfield": {
                "css": "wreck_field",
                "text": {!! json_encode(__('t_external.universe_characteristics.wreckfield')) !!},
                "valueCategory": "wreckfield",
                "valueKey": "enabled",
                "type": "binary"
            },
            "universe_big": {
                "css": "universe_big",
                "text": {!! json_encode(__('t_external.universe_characteristics.universe_big')) !!},
                "valueCategory": "size",
                "valueKey": "galaxies_max",
                "condition": "tooltip.size.galaxies_max > 9",
                "type": "range"
            }
        });
    });
</script>
</body>
</html>
