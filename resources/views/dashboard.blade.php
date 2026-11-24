<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DOH Catering Monitoring</title>
    <script>
        try {
            if (localStorage.getItem('catering-theme') === 'dark') {
                document.documentElement.dataset.theme = 'dark';
            }
        } catch {
            // The theme can still be changed when browser storage is unavailable.
        }
    </script>
    <link rel="stylesheet" href="{{ asset('portal.css') }}">
    <link rel="stylesheet" href="{{ asset('catering.css') }}">
    <script src="{{ asset('catering.js') }}" defer></script>
</head>
<body class="monitoring-page">
    <div class="monitoring-layout">
        <aside class="monitoring-sidebar">
            <nav aria-label="Catering records">
                <div class="monitoring-brand">
                    <img src="{{ asset('doh-seal.png') }}" alt="Department of Health seal" width="80" height="80">
                    <strong>Department of Health</strong>
                </div>
                <ul>
                    @foreach ($tabs as $type => $label)
                        <li>
                            <a href="{{ route('dashboard', ['view' => $type]) }}" @if ($kind === $type) aria-current="page" @endif>
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
                <form class="account" action="{{ route('logout') }}" method="post">
                    @csrf
                    <span>{{ auth()->user()->name }}</span>
                    <button type="submit">Sign out</button>
                </form>
            </nav>
        </aside>
        <main class="monitoring-main">
            <div class="monitoring-heading">
                <h1>{{ $kind === 'overview' ? 'Dashboard' : 'Catering Monitoring' }}</h1>
                <button class="dashboard-action theme-toggle" type="button" role="switch" aria-checked="false" data-theme-toggle hidden>
                    <span class="theme-toggle-track" aria-hidden="true"></span>
                    Dark mode
                </button>
            </div>
            @if ($kind === 'overview')
                @include('dashboard-overview')
            @else
            <section class="metrics" aria-label="Record totals">
                <div><span>Purchase orders</span><strong>{{ number_format($summary->get('order')->total ?? 0) }}</strong></div>
                <div><span>Recorded PO value</span><strong>₱{{ number_format($summary->get('order')->amount ?? 0, 2) }}</strong></div>
                <div><span>Billing entries</span><strong>{{ number_format($summary->get('billing')->total ?? 0) }}</strong></div>
                <div><span>Supplier evaluations</span><strong>{{ number_format($summary->get('evaluation')->total ?? 0) }}</strong></div>
            </section>

            <section class="records-panel" aria-labelledby="records-heading">
                @if ($kind === 'order')
                    <div class="po-entry">
                        <button class="primary" type="button" data-open-dialog="new-po-dialog" aria-haspopup="dialog">+ New PO</button>
                    </div>
                    @include('purchase-order-form')
                    @if (session('po_saved'))
                        <p class="po-saved" role="status">{{ session('po_saved') }}</p>
                    @endif
                @elseif ($kind === 'billing')
                    <div class="po-entry">
                        <button class="primary" type="button" data-open-dialog="new-billing-dialog" aria-haspopup="dialog">+ New Billing Entry</button>
                    </div>
                    @include('billing-entry-form')
                    @if (session('billing_saved'))
                        <p class="po-saved" role="status">{{ session('billing_saved') }}</p>
                    @endif
                @elseif ($kind === 'ntc')
                    <div class="po-entry">
                        <button class="primary" type="button" data-open-dialog="new-ntc-dialog" aria-haspopup="dialog">+ New NTC Entry</button>
                    </div>
                    @include('ntc-entry-form')
                    @if (session('ntc_saved'))
                        <p class="po-saved" role="status">{{ session('ntc_saved') }}</p>
                    @endif
                @endif
                <div class="records-toolbar">
                    <div>
                        <h2 id="records-heading">{{ $tabs[$kind] }}</h2>
                        <p>{{ number_format($records->total()) }} {{ Str::plural('record', $records->total()) }}</p>
                    </div>
                    <form action="{{ route('dashboard') }}" method="get" class="record-filters">
                        <input type="hidden" name="view" value="{{ $kind }}">
                        @unless ($kind === 'ntc')
                            <label>Record source
                                <select name="sheet">
                                    <option value="">All sources</option>
                                    @foreach ($sheets as $option)
                                        <option value="{{ $option }}" @selected($sheet === $option)>{{ $offices[$option] ?? $option }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endunless
                        <label>Search records
                            <input type="search" name="q" value="{{ $search }}" maxlength="120" placeholder="{{ $kind === 'ntc' ? 'PO, caterer, activity, or NTC' : 'PO, caterer, or activity' }}">
                        </label>
                        <button class="primary" type="submit">Apply</button>
                    </form>
                </div>

                <div class="table-scroll" tabindex="0" role="region" aria-label="{{ $tabs[$kind] }} table">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ match ($kind) { 'ntc' => 'NTC No.', 'order' => 'PO number', 'billing' => 'PO Number', default => 'PO / contract' } }}</th>
                                @if ($kind === 'ntc')
                                    <th scope="col">{{ $ntcFields['control_number'][0] }}</th>
                                @endif
                                <th scope="col">{{ $kind === 'billing' ? 'Caterer/Supplier' : 'Caterer and activity' }}</th>
                                @if ($kind === 'billing')
                                    <th scope="col">Status</th>
                                    <th scope="col" class="numeric">Partial Amount</th>
                                    <th scope="col" class="numeric">Remaining Balance</th>
                                    <th scope="col">Actions</th>
                                @elseif ($kind === 'ntc')
                                    <th scope="col">Date Received</th>
                                    <th scope="col" class="numeric">NTC Amount</th>
                                    <th scope="col">Actions</th>
                                @elseif ($kind === 'order')
                                    <th scope="col">ORS number</th>
                                    <th scope="col" class="numeric">PO Amount</th>
                                    <th scope="col">Actions</th>
                                @else
                                    <th scope="col">Sheet</th>
                                    <th scope="col">Rating</th>
                                    <th scope="col" class="numeric">Score</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($records as $record)
                                @php
                                    $details = json_decode($record->details, true);
                                    $viewDetails = $details;
                                    $money = fn ($value) => is_numeric($value) ? '₱'.number_format($value, 2) : $value;
                                    $document = null;
                                    if ($kind === 'billing') {
                                        $billingOrderId = $billingOrderIds[$record->id] ?? null;
                                        $billingBalanceCents = $billingOrders->get($billingOrderId)?->balance_cents;
                                        $remainingBalance = $billingBalanceCents === null ? $record->reported_balance : $billingBalanceCents / 100;
                                        $status = $record->status ?? ($details[$billingColumns['progress'][2]] ?? null);
                                        $partialAmount = $money($record->partial_amount ?? ($details[$billingColumns['partial_amount'][2]] ?? null));
                                        $viewDetails = ['PO Number' => $record->reference, 'Caterer/Supplier' => $record->caterer];
                                        foreach ($billingColumns as $field => [$label, , $key]) {
                                            $label = match ($field) {
                                                'forwarded_to_end_user' => 'Forwarded to (end user)',
                                                'forwarded_to_dv' => 'Forwarded to (DV processing)',
                                                default => $label,
                                            };
                                            $viewDetails[$label] = match ($field) {
                                                'progress' => $status,
                                                'partial_amount' => $partialAmount,
                                                default => $details[$key] ?? null,
                                            };
                                            if ($field === 'partial_amount') {
                                                $viewDetails['Remaining Balance'] = $money($remainingBalance);
                                            }
                                        }
                                        $viewDetails += array_diff_key($details, array_flip([
                                            ...array_column($billingColumns, 2),
                                            $billingFilePathKey,
                                            \App\Http\Controllers\CateringDashboardController::BILLING_EDITED,
                                            \App\Http\Controllers\CateringDashboardController::BILLING_ORDER_ID,
                                            'REMAINING BALANCE (G)',
                                        ]));
                                        if (isset($details[$billingFilePathKey])) {
                                            $document = [
                                                'label' => $billingColumns['scan'][0],
                                                'previewId' => 'billing-document-'.$record->id,
                                                'filename' => $details[$billingColumns['scan'][2]],
                                                'previewUrl' => route('billing-entries.document', ['id' => $record->id, 'preview' => 1]),
                                                'downloadUrl' => route('billing-entries.document', $record->id),
                                            ];
                                        }
                                    } elseif ($kind === 'ntc') {
                                        $viewDetails = [];
                                        foreach ($ntcFields as $field => [$label, $type, $key]) {
                                            $value = match ($field) {
                                                'reference', 'caterer', 'activity' => $record->{$field},
                                                default => $details[$key] ?? null,
                                            };
                                            $viewDetails[$label] = $type === 'amount' ? $money($value) : $value;
                                        }
                                        $viewDetails += array_diff_key($details, array_flip([
                                            ...array_column($ntcFields, 2),
                                            $ntcFilePathKey,
                                            \App\Http\Controllers\CateringDashboardController::NTC_EDITED,
                                        ]));
                                        if (isset($details[$ntcFilePathKey])) {
                                            $document = [
                                                'label' => $ntcFields['scan'][0],
                                                'previewId' => 'ntc-document-'.$record->id,
                                                'filename' => $details[$ntcFields['scan'][2]],
                                                'previewUrl' => route('notices.document.preview', $record->id),
                                                'downloadUrl' => route('notices.document', $record->id),
                                            ];
                                        }
                                    }
                                    if ($kind === 'order') {
                                        $province = $record->province ?? $record->source_sheet;
                                        $poAmount = $record->po_amount === null ? null : '₱'.number_format($record->po_amount, 2);
                                        $orderValues = [
                                            'office' => $province,
                                            'reference' => $record->reference,
                                            'caterer' => $record->caterer,
                                            'activity' => $record->activity,
                                            'po_amount' => $record->po_amount,
                                        ];
                                        $viewDetails = [
                                            'Province' => $offices[$province] ?? ($province === 'Manual entries' ? null : $province),
                                            'PO number' => $record->reference,
                                            'Caterer' => $record->caterer,
                                            'PO Amount' => $poAmount,
                                            'Activity' => $record->activity,
                                        ];
                                        foreach ($poDetails as $field => [$label, $workbookLabel]) {
                                            $value = array_key_exists($label, $details) ? $details[$label] : ($details[$workbookLabel] ?? null);
                                            $orderValues[$field] = $value;
                                            $viewDetails[$label] = $value;
                                        }
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <button class="record-trigger" type="button" data-open-dialog="record-{{ $record->id }}" aria-haspopup="dialog">
                                            {{ $record->reference ?? ($kind === 'ntc' ? 'No NTC number' : 'View details') }}
                                        </button>
                                        <dialog class="record-dialog" id="record-{{ $record->id }}" aria-labelledby="record-title-{{ $record->id }}">
                                            <div class="record-dialog-header">
                                                <div>
                                                    <h2 id="record-title-{{ $record->id }}">{{ $record->reference ?? 'Record details' }}</h2>
                                                    @unless ($kind === 'order')
                                                        <p>{{ $record->source_sheet }}@if ($record->source_row !== null) · Row {{ $record->source_row }}@endif @if ($record->order_row) · PO row {{ $record->order_row }}@endif</p>
                                                    @endunless
                                                </div>
                                                <form method="dialog">
                                                    <button type="submit" autofocus>Close</button>
                                                </form>
                                            </div>
                                            <dl class="record-detail">
                                                @foreach ($viewDetails as $label => $value)
                                                    <dt>{{ preg_replace('/ \([A-Z]+\)$/', '', $label) }}</dt>
                                                    @if ($document !== null && $document['label'] === $label)
                                                        <dd>@include('scanned-document-preview', $document)</dd>
                                                    @else
                                                        <dd>{{ $value ?? '—' }}</dd>
                                                    @endif
                                                @endforeach
                                            </dl>
                                        </dialog>
                                        @if ($record->reference_is_date)
                                            <small>Stored as a date in Excel</small>
                                        @endif
                                    </td>
                                    @if ($kind === 'ntc')
                                        <td class="record-value">{{ $viewDetails[$ntcFields['control_number'][0]] ?? '—' }}</td>
                                    @endif
                                    <td>
                                        <strong>{{ $record->caterer ?? 'Caterer not specified' }}</strong>
                                        @unless ($kind === 'billing')
                                            <span>{{ $record->activity }}</span>
                                        @endunless
                                    </td>
                                    @if ($kind === 'billing')
                                        <td class="record-value">{{ $status ?? '—' }}</td>
                                        <td class="numeric">{{ $partialAmount ?? '—' }}</td>
                                        <td class="numeric">{{ $money($remainingBalance) ?? '—' }}</td>
                                        <td>
                                            <button class="record-edit" type="button" data-open-dialog="edit-billing-{{ $record->id }}" aria-haspopup="dialog" aria-label="Edit billing entry {{ $record->reference }}" title="Edit billing entry">
                                                <span aria-hidden="true">&#9998;</span>
                                            </button>
                                            @include('billing-entry-form', ['billing' => $record, 'billingDetails' => $details, 'billingBalanceCents' => $billingBalanceCents])
                                        </td>
                                    @elseif ($kind === 'ntc')
                                        <td class="record-value">{{ $viewDetails['Date Received'] ?? '—' }}</td>
                                        <td class="numeric">{{ $viewDetails['NTC Amount'] ?? '—' }}</td>
                                        <td>
                                            <button class="record-edit" type="button" data-open-dialog="edit-ntc-{{ $record->id }}" aria-haspopup="dialog" aria-label="Edit NTC entry {{ $record->reference }}" title="Edit NTC entry">
                                                <span aria-hidden="true">&#9998;</span>
                                            </button>
                                            @include('ntc-entry-form', ['notice' => $record, 'noticeDetails' => $details])
                                        </td>
                                    @elseif ($kind === 'order')
                                        <td class="record-value">{{ $orderValues['ors_number'] ?? '—' }}</td>
                                        <td class="numeric">{{ $poAmount ?? '—' }}</td>
                                        <td>
                                            <button class="record-edit" type="button" data-open-dialog="edit-po-{{ $record->id }}" aria-haspopup="dialog" aria-label="Edit PO {{ $record->reference }}" title="Edit purchase order">
                                                <span aria-hidden="true">&#9998;</span>
                                            </button>
                                            @include('purchase-order-form', ['orderId' => $record->id, 'orderValues' => $orderValues])
                                        </td>
                                    @else
                                        <td>{{ $record->source_sheet }}<small>{{ $record->period }}</small></td>
                                        <td class="record-value">{{ $record->status ?? '—' }}</td>
                                        <td class="numeric">
                                            {{ $record->score === null ? '—' : rtrim(rtrim(number_format($record->score, 3), '0'), '.') }}
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="{{ in_array($kind, ['billing', 'ntc'], true) ? 6 : 5 }}" class="empty-records">No records match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($records->hasPages())
                    <nav class="records-pages" aria-label="Record pages">
                        <span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
                        <div>
                            @if ($records->previousPageUrl()) <a href="{{ $records->previousPageUrl() }}" rel="prev">Previous</a> @endif
                            @if ($records->nextPageUrl()) <a href="{{ $records->nextPageUrl() }}" rel="next">Next</a> @endif
                        </div>
                    </nav>
                @endif
            </section>
            @endif
        </main>
    </div>
</body>
</html>

