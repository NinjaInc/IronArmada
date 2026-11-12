@php($rel = 'dropdown' . $rel)
<select @if(!empty($id)) id="{{ $id }}" @endif name="{{ $name }}" class="w200 dropdownInitialized" style="display: none;">
    @foreach ($options as $optValue => $optLabel)
        <option value="{{ $optValue }}" @selected((int)$value === (int)$optValue)>{{ $optLabel }}</option>
    @endforeach
</select><span class="dropdown currentlySelected w200" rel="{{ $rel }}" style="width: 200px;"><a class="undefined" data-value="{{ $value }}" rel="{{ $rel }}" href="javascript:void(0);">{{ $options[$value] ?? '' }}</a></span>
