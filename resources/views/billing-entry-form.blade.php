@php
    $editing = isset($billing);
    $dialogId = $editing ? 'edit-billing-'.$billing->id : 'new-billing-dialog';
    $prefix = $editing ? $dialogId : 'billing';
    $bag = $editing ? 'billingEdit'.$billing->id : 'billingEntry';
    $hasErrors = $errors->getBag($bag)->any();
    $currentBalanceCents = $billingBalanceCents ?? null;
    $currentPaymentCents = (int) round(($billing->partial_amount ?? 0) * 100);
    $recipientLabels = [
        'forwarded_to_end_user' => 'Forwarded to (end user)',
        'forwarded_to_dv' => 'Forwarded to (DV processing)',
    ];
@endphp
<dialog class="record-dialog po-entry-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title" @if ($hasErrors) data-auto-open @endif>
    <div class="record-dialog-header">
        <h2 id="{{ $dialogId }}-title">{{ $editing ? 'Edit billing entry' : 'New billing entry' }}</h2>
        <form method="dialog">
            <button type="submit">Close</button>
        </form>
    </div>
    <form action="{{ $editing ? route('billing-entries.update', $billing->id) : route('billing-entries.store') }}" method="post" enctype="multipart/form-data" class="po-entry-form" data-billing-form>
        @csrf
        @if ($editing) @method('PATCH') @endif
        <div class="po-entry-field">
            <label for="{{ $prefix }}-purchase-order">PO Number</label>
            <select id="{{ $prefix }}-purchase-order" name="purchase_order_id" @required(! $editing) autofocus
                @error('purchase_order_id', $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-order" @enderror>
                <option value="" data-caterer="{{ $billing->caterer ?? '' }}" data-end-user="{{ $billingDetails['FORWARDED TO (R)'] ?? '' }}"
                    data-balance-cents="{{ $currentBalanceCents === null ? '' : $currentBalanceCents + $currentPaymentCents }}">
                    {{ $editing ? ($billing->reference ?? 'Keep current purchase order') : 'Select a purchase order' }}
                </option>
                @foreach ($billingOrders as $order)
                    <option value="{{ $order->id }}" data-caterer="{{ $order->caterer }}" data-end-user="{{ $order->end_user }}"
                        data-balance-cents="{{ $order->balance_cents === null ? '' : $order->balance_cents + ($editing && $order->id === ($billingOrderIds[$billing->id] ?? null) ? $currentPaymentCents : 0) }}"
                        @selected($hasErrors && old('purchase_order_id') == $order->id)>
                        {{ $order->reference }}@if ($order->caterer) — {{ $order->caterer }}@endif
                    </option>
                @endforeach
            </select>
            @error('purchase_order_id', $bag)
                <p id="{{ $prefix }}-error-order" class="error">{{ $message }}</p>
            @enderror
            @if (! $editing && $billingOrders->isEmpty())
                <small>Add a purchase order in Purchase orders before creating a billing entry.</small>
            @endif
        </div>
        <div class="po-entry-field">
            <label for="{{ $prefix }}-caterer">Caterer/Supplier</label>
            <input id="{{ $prefix }}-caterer" type="text" value="{{ $billing->caterer ?? '' }}" data-billing-caterer readonly>
        </div>
        @foreach ($billingColumns as $field => [$label, $type, $detailKey])
            @php
                $storedValue = match ($field) {
                    'progress' => $billing->status ?? null,
                    'partial_amount' => $billing->partial_amount ?? null,
                    default => null,
                } ?? ($billingDetails[$detailKey] ?? null);
                $inputType = $type === 'date' && isset($billing->source_row) ? 'text' : $type;
            @endphp
            <div class="po-entry-field">
                <label for="{{ $prefix }}-{{ $field }}">{{ $recipientLabels[$field] ?? $label }}</label>
                <input id="{{ $prefix }}-{{ $field }}" name="{{ $field }}" type="{{ $inputType }}"
                    @if ($type !== 'file') value="{{ $hasErrors ? old($field) : $storedValue }}" @endif
                    @if ($type === 'file') accept=".pdf,.jpg,.jpeg,.png" @endif
                    @if ($type === 'text') maxlength="255" @endif
                    @if ($type === 'number') inputmode="decimal" min="0" max="9999999999999.99" step="0.01" @endif
                    @error($field, $bag) aria-invalid="true" aria-describedby="{{ $prefix }}-error-{{ $field }}" @enderror>
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
            @if ($field === 'partial_amount')
                <div class="po-entry-field">
                    <label for="{{ $prefix }}-balance">Remaining Balance</label>
                    <output id="{{ $prefix }}-balance" data-billing-balance>{{ $currentBalanceCents === null ? '—' : '₱'.number_format($currentBalanceCents / 100, 2) }}</output>
                </div>
            @endif
        @endforeach
        <button class="primary" type="submit" @disabled(! $editing && $billingOrders->isEmpty())>{{ $editing ? 'Save changes' : 'Save billing entry' }}</button>
    </form>
</dialog>
