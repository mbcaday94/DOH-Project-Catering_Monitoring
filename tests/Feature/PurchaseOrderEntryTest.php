<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_can_add_multiple_purchase_orders(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = [
            'office' => 'PDOHO SDS',
            'reference' => '2026-11-123',
            'caterer' => 'Test Caterer',
            'activity' => 'Staff orientation',
            'po_amount' => '25000.50',
            'end_user' => 'Training Unit',
            'procurement_mode' => 'SVP',
            'delivery_term' => '45 calendar days',
            'date_conformed' => '2026-11-15',
            'activity_date' => '2026-11-20',
            'fund_source' => 'GAA 2026',
            'ors_number' => '02-101101-2026-11-001',
        ];

        $this->post(route('purchase-orders.store'), $entry)->assertRedirect(route('dashboard', ['view' => 'order']));
        $this->post(route('purchase-orders.store'), array_replace($entry, ['reference' => '2026-11-124']))
            ->assertRedirect(route('dashboard', ['view' => 'order']));

        $this->assertDatabaseCount('catering_records', 2);
        $this->assertDatabaseHas('catering_records', [
            'reference' => '2026-11-123',
            'po_amount' => '25000.50',
            'partial_amount' => null,
            'status' => null,
            'reported_balance' => null,
            'source_row' => null,
            'source_sheet' => 'PDOHO SDS',
        ]);
        $this->get(route('dashboard'))->assertOk()->assertSee([
            'New PO', '2026-11-124', '50,001.00', 'PDOHO-SDS', 'PDOHO-SDN', 'PDOHO-ADN', 'PDOHO-PDI', 'BUTUAN CITY/PDOHO ADN',
            'Training Unit', 'SVP', '45 calendar days', '2026-11-15', '2026-11-20', 'GAA 2026', '02-101101-2026-11-001',
        ])->assertSee('id="new-po-dialog"', false)
            ->assertDontSee('data-auto-open')
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="partial_amount"', false)
            ->assertDontSee('name="reported_balance"', false)
            ->assertDontSee(['Progress', 'Partial amount', 'Remaining Balance', 'Entered by']);
    }

    public function test_guests_cannot_add_orders_and_invalid_input_is_rejected(): void
    {
        $this->post(route('purchase-orders.store'), [])->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->followingRedirects()
            ->post(route('purchase-orders.store'), ['po_amount' => '-1'])
            ->assertOk()
            ->assertSee(['data-auto-open', 'po-error-office', 'po-error-reference', 'po-error-caterer', 'po-error-activity', 'po-error-po_amount']);

        $this->assertDatabaseEmpty('catering_records');
    }

    public function test_editing_a_purchase_order_updates_only_the_selected_row(): void
    {
        $order = [
            'source_file' => 'catering.xlsx',
            'source_sheet' => 'PDOHO SDS',
            'source_row' => 4,
            'kind' => 'order',
            'reference' => '2027-01-0001',
            'caterer' => 'Original Caterer',
            'activity' => 'Staff meeting',
            'po_amount' => '5000.00',
            'details' => json_encode(['ORS NO. (O)' => 'OLD-ORS', 'DATE OF ACTIVITY BASED ON PO (M)' => 'January 2–3, 2027']),
        ];
        $id = DB::table('catering_records')->insertGetId($order);
        $otherId = DB::table('catering_records')->insertGetId(array_replace($order, ['source_row' => 5]));
        $url = route('purchase-orders.update', $id);
        $this->patch($url)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-open-dialog="edit-po-'.$id.'"', false)
            ->assertSee('value="OLD-ORS"', false);

        $this->from(route('dashboard'))->patch($url, [])->assertSessionHasErrorsIn('purchaseOrderEdit'.$id, ['office', 'reference']);

        $this->patch($url, [
            'office' => 'PDOHO SDN',
            'reference' => '2027-01-0001',
            'caterer' => 'Updated Caterer',
            'activity' => 'Staff meeting',
            'po_amount' => '6500.25',
            'activity_date' => 'January 2–3, 2027',
            'ors_number' => '',
        ])->assertRedirect(route('dashboard', ['view' => 'order']));

        $this->assertDatabaseHas('catering_records', [
            'id' => $id, 'source_sheet' => 'PDOHO SDS', 'source_row' => 4,
            'province' => 'PDOHO SDN', 'po_amount' => '6500.25',
        ]);
        $this->assertDatabaseHas('catering_records', ['id' => $otherId, 'caterer' => 'Original Caterer', 'province' => null]);
        $this->get(route('dashboard', ['sheet' => 'PDOHO SDN']))->assertOk()
            ->assertSee(['Updated Caterer', '6,500.25', 'January 2–3, 2027'])
            ->assertDontSee(['Original Caterer', 'OLD-ORS']);
    }
}
