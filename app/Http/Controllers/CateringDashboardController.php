<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CateringDashboardController extends Controller
{
    private const OFFICES = [
        'PDOHO SDS' => 'PDOHO-SDS',
        'PDOHO SDN' => 'PDOHO-SDN',
        'PDOHO ADN' => 'PDOHO-ADN',
        'PDOHO PDI' => 'PDOHO-PDI',
        'BUTUAN CITYPDOHO ADN' => 'BUTUAN CITY/PDOHO ADN',
    ];

    private const NTC_PO = 'details->PO# NO. (F)';

    private const NTC_DOCUMENT = 'details->SCANNED NTC (P)';

    private const NTC_FILE_PATH = 'Scanned NTC storage path';

    private const BILLING_FILE_PATH = 'Scanned billing storage path';

    private const SCAN_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'];

    private const AMOUNT_RULES = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'];

    public const NTC_EDITED = 'NTC edited';

    public const BILLING_EDITED = 'Billing edited';

    public const BILLING_ORDER_ID = 'Billing purchase order id';

    private const NTC_FIELDS = [
        'dtrack' => ['DTrack No.', 'text', 'DTRACK (A)'],
        'reference' => ['NTC No.', 'text', 'NTC NO. (B)'],
        'control_number' => ['Control No.', 'text', 'CONTROL NO. (S)'],
        'date_received' => ['Date Received', 'date', 'DATE RECEIVED (C)'],
        'time' => ['Time', 'time', 'TIME (D)'],
        'caterer' => ['Caterer/Supplier', 'text', 'SUPPLIER (E)'],
        'po_number' => ['PO No.', 'text', 'PO# NO. (F)'],
        'activity' => ['Activity', 'textarea', 'TITLE OF ACTIVITY (G)'],
        'activity_date' => ['Date of Activity', 'date', 'DATE OF ACTIVITY (H)'],
        'requested_by' => ['Requested by', 'text', 'REQUESTED BY (I)'],
        'ntc_amount' => ['NTC Amount', 'amount', 'NTC AMOUNT (J)'],
        'date_conducted' => ['Date Conducted', 'date', 'DATE CONDUCTED (K)'],
        'billed_amount' => ['Billed Amount/Actual', 'amount', 'BILLED AMOUNT/ACTUAL (L)'],
        'date_received_billing' => ['Date Received Billing', 'date', 'DATE RECEIVED BILLING (M)'],
        'pax' => ['Pax', 'integer', 'PAX (N)'],
        'days' => ['Days', 'integer', 'DAYS (O)'],
        'scan' => ['Scanned NTC Document', 'file', 'SCANNED NTC (P)'],
    ];

    private const PO_DETAILS = [
        'end_user' => ['End User', 'END USER (I)'],
        'procurement_mode' => ['Mode of Procurement', 'MODE OF PROCUREMENT (J)'],
        'delivery_term' => ['Delivery term', 'DELIVERY TERM (K)'],
        'date_conformed' => ['Date conformed', 'DATE CONFORMED (L)'],
        'activity_date' => ['Date of activity based on PO', 'DATE OF ACTIVITY BASED ON PO (M)'],
        'fund_source' => ['Fund Source', 'FUND SOURCE (N)'],
        'ors_number' => ['ORS number', 'ORS NO. (O)'],
    ];

    private const BILLING_COLUMNS = [
        'progress' => ['Status', 'text', 'PROGRESS (D)'],
        'partial_amount' => ['Partial Amount', 'number', 'PARTIAL AMOUNT (E)'],
        'date_conducted' => ['Date Conducted', 'date', 'DATE CONDUCTED (P)'],
        'date_forwarded_to_end_user' => ['DATE FORWARDED TO END USER', 'date', 'DATE FORWARDED TO END USER (FOR ATTACHMENT OF DOCUMENTS) (Q)'],
        'forwarded_to_end_user' => ['FORWARDED TO', 'text', 'FORWARDED TO (R)'],
        'date_forwarded_to_inspector' => ['DATE FORWARDED TO INSPECTOR', 'date', 'DATE FORWARDED TO INSPECTOR (S)'],
        'inspector_name' => ['INSPECTOR NAME', 'text', 'INSPECTOR (T)'],
        'date_returned_to_supply' => ['DATE RETURN TO SUPPLY FROM INSPECTOR', 'date', 'DATE RETURN TO SUPPLY FROM INSPECTOR (U)'],
        'for_dv_processing' => ['FOR DV PROCESSING', 'date', 'FOR DV PROCESSING (V)'],
        'forwarded_to_dv' => ['FORWARDED TO', 'text', 'FORWARDED TO (W)'],
        'scan' => ['Scanned Copy of Billing', 'file', 'SCANNED COPY OF BILLING (AB)'],
    ];

    public function __invoke(Request $request): View
    {
        $tabs = ['overview' => 'Dashboard', 'order' => 'Purchase orders', 'billing' => 'Billing entries', 'ntc' => 'Notice To Conduct Monitoring', 'evaluation' => 'Supplier evaluations'];
        $kind = $request->string('view')->toString();
        $kind = isset($tabs[$kind]) ? $kind : 'order';
        $sheet = $request->string('sheet')->limit(31, '')->toString();
        $search = $request->string('q')->trim()->limit(120, '')->toString();

        if ($kind === 'overview') {
            return $this->overview($request, $tabs);
        }

        $table = DB::table('catering_records');
        $summary = (clone $table)->selectRaw('kind, count(*) as total, sum(po_amount) as amount')
            ->groupBy('kind')->get()->keyBy('kind');
        $records = (clone $table)->where('kind', $kind);
        $source = $kind === 'order' ? 'COALESCE(province, source_sheet)' : 'source_sheet';
        $sheets = $kind === 'ntc' ? collect() : (clone $records)->selectRaw("$source AS record_source")
            ->distinct()->orderBy('record_source')->get()->pluck('record_source');

        if ($sheets->contains($sheet)) {
            $records->where(DB::raw($source), $sheet);
        } else {
            $sheet = '';
        }

        if ($search !== '') {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $records->where(function ($query) use ($pattern, $kind): void {
                $query->whereRaw("lower(reference) like lower(?) escape '!'", [$pattern])
                    ->orWhereRaw("lower(caterer) like lower(?) escape '!'", [$pattern])
                    ->orWhereRaw("lower(activity) like lower(?) escape '!'", [$pattern]);

                if ($kind === 'ntc') {
                    $po = $query->getGrammar()->wrap(self::NTC_PO);
                    $document = $query->getGrammar()->wrap(self::NTC_DOCUMENT);
                    $query->orWhereRaw("lower($po) like lower(?) escape '!'", [$pattern])
                        ->orWhereRaw("lower($document) like lower(?) escape '!'", [$pattern]);
                }
            });
        }

        [$billingOrders, $billingOrderIds] = $kind === 'billing' ? $this->billingBalances() : [collect(), []];

        return view('dashboard', [
            'tabs' => $tabs, 'kind' => $kind, 'sheet' => $sheet, 'sheets' => $sheets, 'offices' => self::OFFICES,
            'search' => $search, 'summary' => $summary, 'poDetails' => self::PO_DETAILS,
            'billingColumns' => self::BILLING_COLUMNS,
            'ntcFields' => self::NTC_FIELDS,
            'ntcFilePathKey' => self::NTC_FILE_PATH,
            'billingFilePathKey' => self::BILLING_FILE_PATH,
            'billingOrders' => $billingOrders,
            'billingOrderIds' => $billingOrderIds,
            'records' => $records->orderByRaw('CASE WHEN source_row IS NULL THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN source_row IS NULL THEN id END DESC')
                ->orderBy('source_sheet')->orderBy('source_row')->orderBy('id')
                ->paginate(20)->appends(['view' => $kind, 'sheet' => $sheet, 'q' => $search]),
        ]);
    }

    private function overview(Request $request, array $tabs): View
    {
        $now = CarbonImmutable::now('Asia/Manila');
        $today = $now->toDateString();
        $requestedMonth = $request->string('month')->toString();
        $month = preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $requestedMonth)
            ? CarbonImmutable::parse($requestedMonth.'-01', $now->timezone)
            : $now->startOfMonth();
        $entries = DB::table('schedule_entries')->where('user_id', $request->user()->id)
            ->orderBy('starts_on')->orderBy('starts_at')->orderBy('id');
        $upcoming = (clone $entries)->where('starts_on', '>=', $today)
            ->where(fn ($query) => $query->where('starts_on', '>', $today)
                ->orWhereNull('starts_at')->orWhere('starts_at', '>=', $now->format('H:i:s')))
            ->limit(6)->get();

        return view('dashboard', [
            'tabs' => $tabs,
            'kind' => 'overview',
            'profile' => $request->user(),
            'today' => $today,
            'month' => $month,
            'upcoming' => $upcoming,
            'calendarEntries' => $entries->whereBetween('starts_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
                ->get()->groupBy('starts_on'),
        ]);
    }

    public function updateDashboardProfile(Request $request): RedirectResponse
    {
        $profile = $request->validateWithBag('dashboardProfile', [
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'work_status' => ['nullable', 'string', 'max:255'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        unset($profile['profile_picture']);

        $path = null;
        if ($picture = $request->file('profile_picture')) {
            $path = $picture->store('profile-pictures', 'local');
            if ($path === false) {
                throw ValidationException::withMessages(['profile_picture' => 'The picture could not be saved. Please try again.'])
                    ->errorBag('dashboardProfile');
            }
            $profile['profile_picture_path'] = $path;
        }

        try {
            $previousPicture = DB::transaction(function () use ($request, $profile, $path): ?string {
                $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
                $previous = $path === null ? null : $user->profile_picture_path;
                $user->forceFill($profile)->save();

                return $previous;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }

        $request->user()->forceFill($profile)->syncOriginal();

        if ($previousPicture !== null) {
            Storage::disk('local')->delete($previousPicture);
        }

        return redirect()->route('dashboard', ['view' => 'overview'])->with('dashboard_saved', 'Profile updated.');
    }

    public function dashboardProfilePicture(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_picture_path;
        $disk = Storage::disk('local');
        abort_unless(is_string($path) && $disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeScheduleEntry(Request $request): RedirectResponse
    {
        $entry = $request->validateWithBag('dashboardSchedule', [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['personal', 'work'])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i'],
        ]);
        DB::table('schedule_entries')->insert($entry + ['user_id' => $request->user()->id]);

        return redirect()->route('dashboard', ['view' => 'overview', 'month' => substr($entry['starts_on'], 0, 7)])
            ->with('dashboard_saved', 'Schedule entry added.');
    }

    private function billingBalances(): array
    {
        $table = DB::table('catering_records');
        $orders = (clone $table)->where('kind', 'order')->whereNotNull('reference')->where('reference', '<>', '')
            ->orderBy('reference')->orderBy('caterer')->orderBy('id')
            ->get(['id', 'reference', 'caterer', 'po_amount', 'source_file', 'source_sheet', 'source_row', 'details'])
            ->keyBy('id');

        $workbookOrders = [];
        $matchingOrders = [];
        foreach ($orders as $order) {
            $order->end_user = $this->purchaseOrderEndUser($order->details);
            $order->balance_cents = $order->po_amount === null ? null : (int) round($order->po_amount * 100);
            if ($order->source_row !== null) {
                $workbookOrders[$order->source_file][$order->source_sheet][$order->source_row] = $order->id;
            }
            $matchingOrders[$order->reference][$order->caterer ?? ''][] = $order->id;
        }

        $billingOrderIds = [];
        foreach ($table->where('kind', 'billing')->select([
            'id', 'reference', 'caterer', 'source_file', 'source_sheet', 'order_row', 'partial_amount',
            'details->'.self::BILLING_ORDER_ID.' as purchase_order_id',
        ])->cursor() as $payment) {
            $matches = $matchingOrders[$payment->reference ?? ''][$payment->caterer ?? ''] ?? [];
            $orderId = $payment->purchase_order_id
                ?? ($workbookOrders[$payment->source_file][$payment->source_sheet][$payment->order_row ?? 0] ?? null)
                ?? (count($matches) === 1 ? $matches[0] : null);
            $order = $orders->get($orderId);
            if ($order !== null) {
                $billingOrderIds[$payment->id] = $order->id;
                if ($order->balance_cents !== null && $payment->partial_amount !== null) {
                    $order->balance_cents -= (int) round($payment->partial_amount * 100);
                }
            }
        }

        return [$orders, $billingOrderIds];
    }

    public function store(Request $request): RedirectResponse
    {
        $entry = $this->validateOrder($request, 'purchaseOrder');

        DB::table('catering_records')->insert($this->orderAttributes($entry, ['Entered by' => $request->user()->name]) + [
            'source_file' => '',
            'source_sheet' => $entry['office'],
            'kind' => 'order',
        ]);

        return redirect()->route('dashboard', ['view' => 'order'])->with('po_saved', 'Purchase order saved.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $query = DB::table('catering_records')->where('kind', 'order')->where('id', $id);
        $record = $query->first();
        abort_if($record === null, 404);

        $currentProvince = $record->province ?? $record->source_sheet;
        $entry = $this->validateOrder($request, 'purchaseOrderEdit'.$id, $currentProvince);
        $details = json_decode($record->details, true, flags: JSON_THROW_ON_ERROR);

        $query->update($this->orderAttributes($entry, $details) + [
            'province' => $entry['office'],
            'reference_is_date' => $record->reference_is_date && $entry['reference'] === $record->reference,
        ]);

        return redirect()->route('dashboard', ['view' => 'order'])->with('po_saved', 'Purchase order updated.');
    }

    public function storeBillingEntry(Request $request): RedirectResponse
    {
        return $this->saveBillingEntry($request);
    }

    public function updateBillingEntry(Request $request, int $id): RedirectResponse
    {
        return $this->saveBillingEntry($request, $id);
    }

    private function saveBillingEntry(Request $request, ?int $id = null): RedirectResponse
    {
        $billing = $id === null ? null : DB::table('catering_records')->where('kind', 'billing')->find($id);
        abort_if($id !== null && $billing === null, 404);
        $previousDetails = $billing === null ? [] : json_decode($billing->details, true, flags: JSON_THROW_ON_ERROR);
        $bag = $id === null ? 'billingEntry' : 'billingEdit'.$id;
        $rules = ['purchase_order_id' => [$id === null ? 'required' : 'nullable', 'integer', 'min:1']];
        $labels = ['purchase_order_id' => 'PO number'];
        foreach (self::BILLING_COLUMNS as $field => [$label, $type, $workbookLabel]) {
            $labels[$field] = strtolower($label);
            if ($billing?->source_row !== null && $type === 'date'
                && $request->input($field) === ($previousDetails[$workbookLabel] ?? null)) {
                $rules[$field] = ['nullable', 'string'];

                continue;
            }
            $rules[$field] = match ($type) {
                'date' => ['nullable', 'date_format:Y-m-d'],
                'file' => ['nullable', ...self::SCAN_RULES],
                'number' => ['nullable', ...self::AMOUNT_RULES],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        $entry = $request->validateWithBag($bag, $rules, [], $labels);
        $attributes = [];
        if (isset($entry['purchase_order_id'])) {
            $order = DB::table('catering_records')->where('kind', 'order')
                ->whereNotNull('reference')->where('reference', '<>', '')->find($entry['purchase_order_id']);
            if ($order === null) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Select an existing purchase order.'])
                    ->errorBag($bag);
            }
            $attributes = [
                'reference' => $order->reference,
                'reference_is_date' => $order->reference_is_date,
                'caterer' => $order->caterer,
            ];
            if ($id !== null) {
                $attributes['order_row'] = null;
            }
            $entry += ['forwarded_to_end_user' => $this->purchaseOrderEndUser($order->details)];
        }

        if (array_key_exists('progress', $entry)) {
            $attributes['status'] = $entry['progress'];
        }
        $attributes += Arr::only($entry, ['partial_amount']);

        $details = [];
        if (isset($order)) {
            $details[self::BILLING_ORDER_ID] = $order->id;
        }
        foreach (self::BILLING_COLUMNS as $field => [, , $workbookLabel]) {
            if ($field !== 'scan' && array_key_exists($field, $entry)) {
                $details[$workbookLabel] = $entry[$field];
            }
        }

        $path = null;
        if ($scan = $request->file('scan')) {
            $path = $scan->store('billing-documents', 'local');
            if ($path === false) {
                throw ValidationException::withMessages(['scan' => 'The document could not be saved. Please try again.'])
                    ->errorBag($bag);
            }
            $details[self::BILLING_COLUMNS['scan'][2]] = $scan->getClientOriginalName();
            $details[self::BILLING_FILE_PATH] = $path;
        }

        try {
            $replacedPath = DB::transaction(function () use ($id, $attributes, $details, $path): ?string {
                $table = DB::table('catering_records');
                if ($id === null) {
                    $table->insert($attributes + [
                        'kind' => 'billing',
                        'source_file' => '',
                        'source_sheet' => 'Manual entries',
                        'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);

                    return null;
                }

                $query = $table->where('kind', 'billing')->where('id', $id);
                $current = $query->lockForUpdate()->first();
                abort_if($current === null, 404);
                $oldDetails = json_decode($current->details, true, flags: JSON_THROW_ON_ERROR);
                $query->update($attributes + [
                    'details' => json_encode([self::BILLING_EDITED => true] + $details + $oldDetails, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);

                return $path === null ? null : ($oldDetails[self::BILLING_FILE_PATH] ?? null);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }

        if ($replacedPath !== null) {
            Storage::disk('local')->delete($replacedPath);
        }

        return redirect()->route('dashboard', ['view' => 'billing'])
            ->with('billing_saved', $id === null ? 'Billing entry saved.' : 'Billing entry updated.');
    }

    public function storeNotice(Request $request): RedirectResponse
    {
        return $this->saveNotice($request);
    }

    public function updateNotice(Request $request, int $id): RedirectResponse
    {
        return $this->saveNotice($request, $id);
    }

    private function saveNotice(Request $request, ?int $id = null): RedirectResponse
    {
        $notice = null;
        $previousDetails = [];
        if ($id !== null) {
            $notice = DB::table('catering_records')->where('kind', 'ntc')->find($id);
            abort_if($notice === null, 404);
            $previousDetails = json_decode($notice->details, true, flags: JSON_THROW_ON_ERROR);
        }

        $bag = $id === null ? 'ntcEntry' : 'ntcEdit'.$id;
        $rules = [];
        $labels = [];
        foreach (self::NTC_FIELDS as $field => [$label, $type, $workbookLabel]) {
            $labels[$field] = $label;
            if ($notice?->source_row !== null && in_array($type, ['date', 'time', 'amount', 'integer'], true)
                && $request->input($field) === ($previousDetails[$workbookLabel] ?? null)) {
                $rules[$field] = ['nullable', 'string'];

                continue;
            }
            $rules[$field] = [$field === 'reference' ? 'required' : 'nullable', ...match ($type) {
                'date' => ['date_format:Y-m-d'],
                'time' => ['date_format:H:i'],
                'amount' => self::AMOUNT_RULES,
                'integer' => ['integer', 'min:0', 'max:2147483647'],
                'textarea' => ['string', 'max:4000'],
                'file' => self::SCAN_RULES,
                default => ['string', 'max:255'],
            }];
        }

        $entry = $request->validateWithBag($bag, $rules, [], $labels);
        $detailChanges = [];
        foreach (self::NTC_FIELDS as $field => [, , $workbookLabel]) {
            if ($field !== 'scan' && array_key_exists($field, $entry)) {
                $detailChanges[$workbookLabel] = $entry[$field];
            }
        }

        $path = null;
        if ($scan = $request->file('scan')) {
            $path = $scan->store('ntc-documents', 'local');
            if ($path === false) {
                throw ValidationException::withMessages(['scan' => 'The document could not be saved. Please try again.'])
                    ->errorBag($bag);
            }
            $detailChanges[self::NTC_FIELDS['scan'][2]] = $scan->getClientOriginalName();
            $detailChanges[self::NTC_FILE_PATH] = $path;
        }

        try {
            $replacedPath = DB::transaction(function () use ($id, $entry, $detailChanges, $path): ?string {
                $table = DB::table('catering_records');
                $attributes = Arr::only($entry, ['reference', 'caterer', 'activity']);
                if ($id === null) {
                    $table->insert($attributes + [
                        'kind' => 'ntc',
                        'source_file' => '',
                        'source_sheet' => 'Manual entries',
                        'details' => json_encode($detailChanges, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);

                    return null;
                }

                $query = $table->where('kind', 'ntc')->where('id', $id);
                $current = $query->lockForUpdate()->first();
                abort_if($current === null, 404);
                $details = json_decode($current->details, true, flags: JSON_THROW_ON_ERROR);
                $query->update($attributes + [
                    'reference_is_date' => $current->reference_is_date && $entry['reference'] === $current->reference,
                    'details' => json_encode([self::NTC_EDITED => true] + $detailChanges + $details, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                ]);

                return $path === null ? null : ($details[self::NTC_FILE_PATH] ?? null);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }

        if ($replacedPath !== null) {
            Storage::disk('local')->delete($replacedPath);
        }

        return redirect()->route('dashboard', ['view' => 'ntc'])
            ->with('ntc_saved', $id === null ? 'NTC entry saved.' : 'NTC entry updated.');
    }

    public function downloadNoticeDocument(int $id): StreamedResponse
    {
        return $this->scannedDocument($id, 'ntc', self::NTC_FILE_PATH, 'scanned-ntc', 'attachment');
    }

    public function previewNoticeDocument(int $id): StreamedResponse
    {
        return $this->scannedDocument($id, 'ntc', self::NTC_FILE_PATH, 'scanned-ntc', 'inline');
    }

    public function billingDocument(Request $request, int $id): StreamedResponse
    {
        return $this->scannedDocument($id, 'billing', self::BILLING_FILE_PATH, 'scanned-billing', $request->boolean('preview') ? 'inline' : 'attachment');
    }

    private function scannedDocument(int $id, string $kind, string $pathKey, string $filename, string $disposition): StreamedResponse
    {
        $record = DB::table('catering_records')->where('kind', $kind)->find($id);
        abort_if($record === null, 404);

        $details = json_decode($record->details, true, flags: JSON_THROW_ON_ERROR);
        $path = $details[$pathKey] ?? null;
        $disk = Storage::disk('local');
        abort_unless(is_string($path) && $disk->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => abort(404),
        };

        return $disk->response($path, $filename.'.'.$extension, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ], $disposition);
    }

    private function purchaseOrderEndUser(string $details): ?string
    {
        $values = json_decode($details, true, flags: JSON_THROW_ON_ERROR);
        [$label, $workbookLabel] = self::PO_DETAILS['end_user'];

        return array_key_exists($label, $values) ? $values[$label] : ($values[$workbookLabel] ?? null);
    }

    private function validateOrder(Request $request, string $bag, ?string $currentProvince = null): array
    {
        $provinces = array_keys(self::OFFICES);
        if ($currentProvince !== null) {
            $provinces[] = $currentProvince;
        }
        $dateRule = $currentProvince === null ? ['date_format:Y-m-d'] : ['string', 'max:255'];

        return $request->validateWithBag($bag, [
            'office' => ['required', Rule::in($provinces)],
            'reference' => ['required', 'string', 'max:255'],
            'caterer' => ['required', 'string', 'max:255'],
            'activity' => ['required', 'string', 'max:4000'],
            'po_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'end_user' => ['nullable', 'string', 'max:255'],
            'procurement_mode' => ['nullable', 'string', 'max:255'],
            'delivery_term' => ['nullable', 'string', 'max:255'],
            'date_conformed' => ['nullable', ...$dateRule],
            'activity_date' => ['nullable', ...$dateRule],
            'fund_source' => ['nullable', 'string', 'max:255'],
            'ors_number' => ['nullable', 'string', 'max:255'],
        ], [], array_map(static fn (array $labels): string => $labels[0], self::PO_DETAILS)
            + ['reference' => 'PO number', 'office' => 'province']);
    }

    private function orderAttributes(array $entry, array $details): array
    {
        foreach (self::PO_DETAILS as $field => [$label]) {
            $details[$label] = $entry[$field] ?? null;
        }

        return Arr::except($entry, [...array_keys(self::PO_DETAILS), 'office']) + [
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ];
    }
}
