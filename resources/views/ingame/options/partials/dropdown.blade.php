{{-- Plain select: the page JS ($('select').ogameDropDown()) builds the styled dropdown and its list. --}}
<select @if(!empty($id)) id="{{ $id }}" @endif name="{{ $name }}" class="w200">
    @foreach ($options as $optValue => $optLabel)
        <option value="{{ $optValue }}" @selected((int)$value === (int)$optValue)>{{ $optLabel }}</option>
    @endforeach
</select>
