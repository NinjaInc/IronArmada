@extends('outgame.layouts.main')

@section('content')

<div id="menu">
    <ul id="tabs">
        <li><a id="tab1" href="#tabContentContainer">{{ __('t_external.nav.home') }}</a></li>
    </ul>
    <br class="clearfloat" />
    <div id="tabContentContainer">
        <div class="tabContent">
            <div id="ajaxContent">
                <div class="inner-box last clearfix">
                    <h2>{{ __('t_external.home.title') }}</h2>

                    @if (session('ban_message'))
                        <span class="help-block" style="color: #e74c3c;">
                            <strong>{{ session('ban_message') }}</strong>
                        </span>
                    @endif

                    @if ($errors->has('email'))
                        <span class="help-block" style="color: #e74c3c;">
                            <strong>{{ $errors->first('email') }}</strong>
                        </span>
                    @endif

                    @if ($errors->has('password'))
                        <span class="help-block">
                            <strong>{{ $errors->first('password') }}</strong>
                        </span>
                    @endif

                    <p>{!! __('t_external.home.description_html') !!}</p>
                </div>
            </div>
        </div>                        </div>
    <div id="contentFooter"></div>
</div>

@endsection
