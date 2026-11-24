@php
    $editing = isset($orderId);
    $bag = $editing ? 'purchaseOrderEdit'.$orderId : 'purchaseOrder';
    $dialogId = $editing ? 'edit-po-'.$orderId : 'new-po-dialog';
    $prefix = $editing ? $dialogId : 'po';
    $formAction = $editing ? route('purchase-orders.update', $orderId) : route('purchase-orders.store');
    $defaults = $orderValues ?? [];
    $fieldValue = fn ($field) => $errors->getBag($bag)->any() ? old($field) : ($defaults[$field] ?? null);
    $provinces = $offices;
    if ($editing && ! isset($provinces[$defaults['office']])) {
        $provinces[$defaults['office']] = $defaults['office'];
    }
@endphp
<dialog class="record-dialog po-entry-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title" @if ($errors->getBag($bag)->any()) data-auto-open @endif>
    <div class="record-dialog-header">
        <h2 id="{{ $dialogId }}-title">{{ $editing ? 'Edit purchase order' : 'New purchase order' }}</h2>
        <form method="dialog">
            <button type="submit">Close</button>
        </form>
    </div>
    <form action="{{ $formAction }}" method="post" class="po-entry-form">
        @csrf
        @if ($editing) @method('PATCH') @endif
        @unless ($editing)
            <div class="po-scan">
                <label for="po-scan-file">Upload scanned PO</label>
                <input id="po-scan-file" type="file" accept=".pdf,.jpg,.jpeg,.png" data-scan-url="{{ route('purchase-orders.scan') }}" aria-describedby="po-scan-help po-scan-status">
                <small id="po-scan-help">PDF, JPG, or PNG up to 2 MB. The scan fills empty fields for you to review.</small>
                <p id="po-scan-status" role="status"></p>
            </div>
        @endunless
        <div class="po-entry-field">
            <label for="{{ $prefix }}-office">{{ config('purchase-order.fields.office.0') }}</label>
            <select id="{{ $prefix }}-office" name="office" required
                @error('office', $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-office" @enderror>
                <option value="">Select a province</option>
                @foreach ($provinces as $value => $label)
                    <option value="{{ $value }}" @selected($fieldValue('office') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('office', $bag)
                <p id="{{ $prefix }}-error-office" class="error">{{ $message }}</p>
            @enderror
        </div>
        @foreach (config('purchase-order.fields') as $field => [$label, $type, $required])
            @continue($type === 'select')
            <div class="po-entry-field">
                <label for="{{ $prefix }}-{{ $field }}">{{ $label }}</label>
                @if ($type === 'textarea')
                    <textarea id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" rows="3" maxlength="4000" required
                        @error($field, $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $field }}" @enderror>{{ $fieldValue($field) }}</textarea>
                @else
                    <input id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" value="{{ $fieldValue($field) }}" type="{{ $editing && $type === 'date' ? 'text' : $type }}"
                        @if ($field === 'reference') autofocus @endif
                        @if ($type === 'number') inputmode="decimal" min="0" max="9999999999999.99" step="0.01" @endif
                        @if ($type === 'text' || ($editing && $type === 'date')) maxlength="255" @endif
                        @required($required)
                        @error($field, $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $field }}" @enderror>
                @endif
                @error($field, $bag)
                    <p id="{{ $prefix }}-error-{{ $field }}" class="error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
        <button class="primary" type="submit">{{ $editing ? 'Save changes' : 'Save purchase order' }}</button>
    </form>
</dialog>
