@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div id="resourcesettingscomponent" class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>{{ __('t_ingame.bots.title') }}</h2>
        </div>

        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.bots.title') }}</h2>
            </div>
            <div class="content">
                <div class="buddylistContent">
                    <p class="box_highlight textCenter no_buddies">{{ __('t_ingame.bots.hint') }}</p>

                    <form action="{{ route('admin.bots.global') }}" method="post" class="group bborder" style="display:block;">
                        {{ csrf_field() }}
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.global_enabled') }}</label>
                            <div class="thefield">
                                <input type="checkbox" name="bots_enabled" value="1" {{ $bots_enabled ? 'checked' : '' }}>
                            </div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.colonise_enabled') }}</label>
                            <div class="thefield"><input type="checkbox" name="bots_colonise_enabled" value="1" {{ $bots_colonise_enabled ? 'checked' : '' }}></div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.raid_enabled') }}</label>
                            <div class="thefield"><input type="checkbox" name="bots_raid_enabled" value="1" {{ $bots_raid_enabled ? 'checked' : '' }}></div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.raid_players') }}</label>
                            <div class="thefield"><input type="checkbox" name="bots_raid_players" value="1" {{ $bots_raid_players ? 'checked' : '' }}></div>
                        </div>
                        <div class="fieldwrapper"><div class="thefield"><input type="submit" class="btn_blue" value="{{ __('t_ingame.bots.save_global') }}"></div></div>
                    </form>

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ingame.bots.report_title') }}</p>
                    <p class="textCenter">
                        {{ __('t_ingame.bots.stat_bots') }}: {{ $stats['bots'] }} |
                        {{ __('t_ingame.bots.stat_colonies') }}: {{ $stats['colonies'] }} |
                        {{ __('t_ingame.bots.stat_raids') }}: {{ $stats['raids_24h'] }} ({{ __('t_ingame.bots.stat_raids_players') }}: {{ $stats['raids_players_24h'] }}) |
                        {{ __('t_ingame.bots.stat_colonisations') }}: {{ $stats['colonisations_24h'] }}
                    </p>
                    <p class="textCenter">
                        @foreach ($stats['by_personality'] as $name => $count)
                            {{ __('t_ingame.bots.personality_' . $name) }}: {{ $count }}@if (!$loop->last) | @endif
                        @endforeach
                    </p>
                    @if (count($raid_log) === 0)
                        <p class="textCenter">{{ __('t_ingame.bots.raid_log_none') }}</p>
                    @else
                        <table class="members bborder" style="width:100%;">
                            <thead><tr>
                                <th>{{ __('t_ingame.bots.raid_time') }}</th><th>{{ __('t_ingame.bots.raid_attacker') }}</th>
                                <th>{{ __('t_ingame.bots.raid_target') }}</th><th>{{ __('t_ingame.bots.raid_owner') }}</th>
                                <th>{{ __('t_ingame.bots.raid_ships') }}</th><th>{{ __('t_ingame.bots.raid_state') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($raid_log as $raid)
                                <tr>
                                    <td>{{ date('Y-m-d H:i', $raid['time']) }}</td>
                                    <td>{{ $raid['attacker'] }}</td>
                                    <td>{{ $raid['target'] }}</td>
                                    <td>{{ $raid['owner'] }}@if (!$raid['target_is_bot']) <b>*</b>@endif</td>
                                    <td>{{ number_format($raid['ships']) }}</td>
                                    <td>{{ $raid['done'] ? __('t_ingame.bots.raid_done') : __('t_ingame.bots.raid_flying') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <p class="textCenter">* {{ __('t_ingame.bots.raid_real_player') }}</p>
                    @endif

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ingame.bots.pirates_title') }}</p>
                    <p class="textCenter">{{ __('t_ingame.bots.pirates_hint') }}</p>
                    <form action="{{ route('admin.bots.pirates') }}" method="post" class="group bborder" style="display:block;">
                        {{ csrf_field() }}
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.pirates_enabled') }}</label>
                            <div class="thefield"><input type="checkbox" name="pirates_enabled" value="1" {{ $pirate_settings['pirates_enabled'] ? 'checked' : '' }}></div>
                        </div>
                        @foreach (['pirates_min_points', 'pirates_min_account_age_days', 'pirates_strength_percent', 'pirates_target_cooldown_hours', 'pirates_max_raids_per_day', 'pirates_min_fleet_value', 'pirates_max_fleet_value'] as $key)
                            <div class="fieldwrapper">
                                <label class="styled textBeefy">{{ __('t_ingame.bots.' . $key) }}</label>
                                <div class="thefield"><input class="textInput w100" type="number" min="0" name="{{ $key }}" value="{{ $pirate_settings[$key] }}"></div>
                            </div>
                        @endforeach
                        <div class="fieldwrapper"><div class="thefield"><input type="submit" class="btn_blue" value="{{ __('t_ingame.bots.save') }}"></div></div>
                    </form>

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ingame.bots.create_title') }}</p>
                    <form action="{{ route('admin.bots.create') }}" method="post" class="group bborder" style="display:block;">
                        {{ csrf_field() }}
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.name') }}</label>
                            <div class="thefield"><input class="textInput w200" type="text" maxlength="30" name="name"></div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.personality') }}</label>
                            <div class="thefield">
                                <select name="personality">
                                    @foreach ($personalities as $personality)
                                        <option value="{{ $personality }}">{{ __('t_ingame.bots.personality_' . $personality) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.difficulty') }}</label>
                            <div class="thefield">
                                <select name="difficulty">
                                    @foreach ($difficulties as $difficulty)
                                        <option value="{{ $difficulty }}" {{ $difficulty === 2 ? 'selected' : '' }}>{{ __('t_ingame.bots.difficulty_' . $difficulty) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="fieldwrapper">
                            <label class="styled textBeefy">{{ __('t_ingame.bots.amount') }}</label>
                            <div class="thefield">
                                <input class="textInput w50" type="number" min="1" max="20" value="1" name="amount">
                                <input type="submit" class="btn_blue" value="{{ __('t_ingame.bots.create') }}">
                            </div>
                        </div>
                    </form>

                    <p class="box_highlight textCenter no_buddies">{{ __('t_ingame.bots.list_title') }}</p>
                    @if ($bots->isEmpty())
                        <p class="textCenter">{{ __('t_ingame.bots.none') }}</p>
                    @else
                        <table class="ia-bot-table" style="width:100%;">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>{{ __('t_ingame.bots.name') }}</th>
                                <th>{{ __('t_ingame.bots.points') }}</th>
                                <th>{{ __('t_ingame.bots.next_turn') }}</th>
                                <th>{{ __('t_ingame.bots.personality') }}</th>
                                <th>{{ __('t_ingame.bots.difficulty') }}</th>
                                <th>{{ __('t_ingame.bots.enabled') }}</th>
                                <th>{{ __('t_ingame.bots.actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($bots as $bot)
                                <tr>
                                        <td>{{ $bot->id }}</td>
                                        <td>{{ $bot->username }}</td>
                                        <td>{{ $bot->highscore?->general ?? 0 }}</td>
                                        <td>{{ $bot->bot_next_action_at > 0 ? date('H:i:s', $bot->bot_next_action_at) : '-' }}</td>
                                        <td>
                                            <select name="personality" form="bot-{{ $bot->id }}">
                                                @foreach ($personalities as $personality)
                                                    <option value="{{ $personality }}" {{ $bot->bot_personality === $personality ? 'selected' : '' }}>{{ __('t_ingame.bots.personality_' . $personality) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select name="difficulty" form="bot-{{ $bot->id }}">
                                                @foreach ($difficulties as $difficulty)
                                                    <option value="{{ $difficulty }}" {{ (int)$bot->bot_difficulty === $difficulty ? 'selected' : '' }}>{{ __('t_ingame.bots.difficulty_' . $difficulty) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="checkbox" name="bot_enabled" value="1" form="bot-{{ $bot->id }}" {{ $bot->bot_enabled ? 'checked' : '' }}></td>
                                        <td>
                                            <form id="bot-{{ $bot->id }}" action="{{ route('admin.bots.update', $bot->id) }}" method="post" style="display:inline;">
                                                {{ csrf_field() }}
                                                <input type="submit" class="btn_blue" value="{{ __('t_ingame.bots.save') }}">
                                                <input type="submit" class="btn_blue" formaction="{{ route('admin.bots.turn', $bot->id) }}" value="{{ __('t_ingame.bots.turn_now') }}">
                                            </form>
                                        </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
            <div class="footer"></div>
        </div>
    </div>

@endsection
