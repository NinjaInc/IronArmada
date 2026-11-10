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
                                <input type="submit" class="btn_blue" value="{{ __('t_ingame.bots.save_global') }}">
                            </div>
                        </div>
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
