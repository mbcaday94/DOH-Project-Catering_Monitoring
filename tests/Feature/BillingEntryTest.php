<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_balance_includes_all_payments_for_the_selected_purchase_order(): void
    {
        $order = [
            'kind' => 'order',
            'source_file' => '',
            'source_sheet' => 'PDOHO SDS',
            'reference' => 'PO-2026-125',
            'caterer' => 'Example Caterer',
            'po_amount' => '5000.00',
            'details' => '{}',
        ];
        $orderId = DB::table('catering_records')->insertGetId($order);
        $otherOrderId = DB::table('catering_records')->insertGetId($order);
        $dashboard = route('dashboard', ['view' => 'billing']);
        $this->actingAs(User::factory()->create());
        foreach ([[$orderId, '1000.25'], [$orderId, '250.50'], [$otherOrderId, '100.00']] as [$id, $amount]) {
            $this->post(route('billing-entries.store'), ['purchase_order_id' => $id, 'partial_amount' => $amount])
                ->assertRedirect($dashboard);
        }

        $this->get($dashboard)->assertOk()->assertSee(['Remaining Balance', '₱3,749.25', '₱4,900.00']);

        $paymentId = DB::table('catering_records')->where('kind', 'billing')->orderBy('id')->value('id');
        $this->patch(route('billing-entries.update', $paymentId), ['partial_amount' => '500.25'])
            ->assertRedirect($dashboard);

        $this->get($dashboard)->assertOk()->assertSee(['₱4,249.25', '₱4,900.00'])->assertDontSee('₱3,749.25');
    }

    public function test_billing_entry_is_saved_and_appears_in_the_billing_table(): void
    {
        Storage::fake('local');
        $dashboard = route('dashboard', ['view' => 'billing']);
        $orderId = DB::table('catering_records')->insertGetId([
            'kind' => 'order',
            'source_file' => '',
            'source_sheet' => 'PDOHO SDS',
            'reference' => '2026-11-123',
            'caterer' => 'Example Caterer',
            'details' => json_encode(['END USER (I)' => 'Training Unit'], JSON_THROW_ON_ERROR),
        ]);
        $this->actingAs(User::factory()->create())->get($dashboard)->assertOk()
            ->assertSee('value="'.$orderId.'" data-caterer="Example Caterer" data-end-user="Training Unit"', false)
            ->assertSee(['Status', 'Partial Amount']);

        $this->post(route('billing-entries.store'), [
            'purchase_order_id' => $orderId,
            'reference' => 'Ignored reference',
            'caterer' => 'Ignored caterer',
            'progress' => 'For processing',
            'partial_amount' => '1250.50',
            'date_conducted' => '2026-11-20',
            'date_forwarded_to_end_user' => '2026-11-21',
            'date_forwarded_to_inspector' => '2026-11-22',
            'inspector_name' => 'Example Inspector',
            'date_returned_to_supply' => '2026-11-23',
            'for_dv_processing' => '2026-11-24',
            'forwarded_to_dv' => 'Accounting Unit',
            'scan' => UploadedFile::fake()->image('billing.png'),
        ])->assertRedirect($dashboard);

        $this->get($dashboard)->assertOk()
            ->assertSee('Billing entry saved.')
            ->assertSeeInOrder(['<th scope="col">Caterer/Supplier</th>', '<th scope="col">Status</th>', '<th scope="col" class="numeric">Partial Amount</th>', '<th scope="col" class="numeric">Remaining Balance</th>', '<th scope="col">Actions</th>'], false)
            ->assertDontSee('<th scope="col">Date Conducted</th>', false)
            ->assertSeeInOrder(['<dt>Status</dt>', '<dd>For processing</dd>', '<dt>Date Conducted</dt>'], false)
            ->assertSeeInOrder(['For processing', '₱1,250.50'])
            ->assertSeeInOrder(['2026-11-123', 'Example Caterer', '2026-11-20', '2026-11-21', 'Training Unit', '2026-11-22', 'Example Inspector', '2026-11-23', '2026-11-24', 'Accounting Unit']);

        $record = DB::table('catering_records')->where('kind', 'billing')->sole();
        $this->assertSame('2026-11-123', $record->reference);
        $this->assertSame('Example Caterer', $record->caterer);
        $this->assertSame('For processing', $record->status);
        $this->assertEquals(1250.50, $record->partial_amount);
        $details = json_decode($record->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Training Unit', $details['FORWARDED TO (R)']);
        $this->assertSame('Accounting Unit', $details['FORWARDED TO (W)']);
        $this->assertSame('billing.png', $details['SCANNED COPY OF BILLING (AB)']);
        Storage::disk('local')->assertExists($details['Scanned billing storage path']);

        $download = route('billing-entries.document', $record->id);
        $preview = route('billing-entries.document', ['id' => $record->id, 'preview' => 1]);
        $this->get($dashboard)->assertOk()
            ->assertSee(['Scanned Copy of Billing', 'billing.png', $preview, $download])
            ->assertDontSee($details['Scanned billing storage path']);
        $this->get($preview)->assertOk()->assertHeader('Content-Disposition', 'inline; filename=scanned-billing.png');
        $this->get($download)->assertDownload('scanned-billing.png');

        $update = route('billing-entries.update', $record->id);
        $this->patch($update, ['progress' => 'Processed', 'partial_amount' => '1500.25'])->assertRedirect($dashboard);
        $this->get($dashboard)->assertOk()
            ->assertSee(['Billing entry updated.', 'Processed', '₱1,500.25', 'Current document: billing.png', 'Edit billing entry 2026-11-123']);
        Storage::disk('local')->assertExists($details['Scanned billing storage path']);

        $this->post($update, [
            '_method' => 'PATCH',
            'scan' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertRedirect($dashboard);
        Storage::disk('local')->assertMissing($details['Scanned billing storage path']);
        $this->get($download)->assertDownload('scanned-billing.jpg');
        $this->assertDatabaseHas('catering_records', [
            'id' => $record->id,
            'reference' => '2026-11-123',
            'caterer' => 'Example Caterer',
            'status' => 'Processed',
        ]);

        auth()->logout();
        $this->get($preview)->assertRedirect(route('login'));
    }

    public function test_invalid_billing_entry_reopens_the_form_with_errors(): void
    {
        $this->post(route('billing-entries.store'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard', ['view' => 'billing']))
            ->followingRedirects()
            ->post(route('billing-entries.store'), ['date_conducted' => 'invalid', 'for_dv_processing' => 'Ready', 'partial_amount' => '-1'])
            ->assertOk()
            ->assertSee(['data-auto-open', 'billing-error-order', 'billing-error-date_conducted', 'billing-error-for_dv_processing', 'billing-error-partial_amount']);

        $this->assertDatabaseEmpty('catering_records');
    }

    public function test_billing_entry_rejects_a_record_that_is_not_a_purchase_order(): void
    {
        $recordId = DB::table('catering_records')->insertGetId([
            'kind' => 'billing',
            'source_file' => '',
            'source_sheet' => 'Manual entries',
            'reference' => '2026-11-123',
            'details' => '{}',
        ]);

        $this->actingAs(User::factory()->create())->post(route('billing-entries.store'), [
            'purchase_order_id' => $recordId,
        ])->assertSessionHasErrorsIn('billingEntry', ['purchase_order_id']);

        $this->assertDatabaseCount('catering_records', 1);
    }
}
