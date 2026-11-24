@php
    $editing = isset($notice);
    $dialogId = $editing ? 'edit-ntc-'.$notice->id : 'new-ntc-dialog';
    $prefix = $editing ? $dialogId : 'ntc';
    $bag = $editing ? 'ntcEdit'.$notice->id : 'ntcEntry';
    $hasErrors = $errors->getBag($bag)->any();
@endphp
<dialog class="record-dialog po-entry-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title" @if ($hasErrors) data-auto-open @endif>
    <div class="record-dialog-header">
        <h2 id="{{ $dialogId }}-title">{{ $editing ? 'Edit NTC entry' : 'New NTC entry' }}</h2>
        <form method="dialog">
            <button type="submit">Close</button>
        </form>
    </div>
    <form action="{{ $editing ? route('notices.update', $notice->id) : route('notices.store') }}" method="post" enctype="multipart/form-data" class="po-entry-form">
        @csrf
        @if ($editing) @method('PATCH') @endif
        @foreach ($ntcFields as $field => [$label, $type, $detailKey])
            @php
                $storedValue = match ($field) {
                    'reference', 'caterer', 'activity' => $notice->{$field} ?? null,
                    default => $noticeDetails[$detailKey] ?? null,
                };
                $value = $hasErrors ? old($field) : $storedValue;
                $inputType = in_array($type, ['amount', 'integer'], true) ? 'number' : $type;
                if ($editing && $notice->source_row !== null && in_array($type, ['date', 'time', 'amount', 'integer'], true)) {
                    $inputType = 'text';
                }
            @endphp
            <div class="po-entry-field">
                <label for="{{ $prefix }}-{{ $field }}">{{ $label }}</label>
                @if ($type === 'textarea')
                    <textarea id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" rows="3" maxlength="4000"
                        @error($field, $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $field }}" @enderror>{{ $value }}</textarea>
                @else
                    <input id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" type="{{ $inputType }}"
                        @if ($type !== 'file') value="{{ $value }}" @endif
                        @if ($type === 'text') maxlength="255" @endif
                        @if ($type === 'amount') min="0" max="9999999999999.99" step="0.01" @endif
                        @if ($type === 'integer') min="0" max="2147483647" step="1" @endif
                        @if ($type === 'file') accept=".pdf,.jpg,.jpeg,.png" @endif
                        @required($field === 'reference')
                        @error($field, $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $field }}" @enderror>
                @endif
                @if ($type === 'file')
                    <small>
                        @if ($storedValue)
                            Current document: {{ $storedValue }}. Choose a file to replace it.
                        @endif
                        PDF, JPG, or PNG up to 2 MB.
                    </small>
                @endif
                @error($field, $bag)
                    <p id="{{ $prefix }}-error-{{ $field }}" class="error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
        <button class="primary" type="submit">{{ $editing ? 'Save changes' : 'Save NTC entry' }}</button>
    </form>
</dialog>
