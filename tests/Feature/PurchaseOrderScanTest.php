<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PurchaseOrderScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_reads_fields_without_creating_an_order(): void
    {
        Process::fake([
            '*' => Process::result(output: <<<'TEXT'
PO Number: 2027 - 01 - 0001
Supplier:
Example Caterer
Province: PDOHO-SDS
Grand Total: 99,000.00
Total Amount: 90,000.00
Partial Amount: 15,000.00
Remaining Balance: 30,000.00
Progress: For payment
PO Amount: PHP 45,000.50
Activity: Planning workshop
Requested By: Other Department
End User: Training Unit
Mode of Procurement: SVP
Delivery Terms: 45 calendar days
Date conformed: November 20, 2026
Activity Date: 12/01/2026
Fund Source: GAA 2026
ORS No.: 02-101101-2026-11-001
TEXT),
        ]);

        $this->actingAs(User::factory()->create())->post(route('purchase-orders.scan'), [
            'scan' => UploadedFile::fake()->image('scan.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertExactJson(['fields' => [
            'reference' => '2027-01-0001',
            'caterer' => 'Example Caterer',
            'office' => 'PDOHO-SDS',
            'po_amount' => '45000.50',
            'activity' => 'Planning workshop',
            'end_user' => 'Training Unit',
            'procurement_mode' => 'SVP',
            'delivery_term' => '45 calendar days',
            'date_conformed' => '2026-11-20',
            'activity_date' => '2026-12-01',
            'fund_source' => 'GAA 2026',
            'ors_number' => '02-101101-2026-11-001',
        ]]);

        $this->assertDatabaseEmpty('catering_records');
    }

    public function test_scan_maps_supplier_description_and_grand_total_from_purchase_order(): void
    {
        Process::fake([
            '*' => Process::result(output: <<<'TEXT'
Grand Total: 117,600.00
Description: LUNCH PM SNACKS CONDUCT OF REGULAR MANAGEMENT COMMITTEE MEETING
Supplier. BUTUAN SOUTHVILLE HOTEL AND RESTAURANT
PO NO.: 2026. 04 286
Grand total:
82,800.00
34,800.00
117,600.00
TEXT),
        ]);

        $this->actingAs(User::factory()->create())->post(route('purchase-orders.scan'), [
            'scan' => UploadedFile::fake()->image('scan.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertExactJson(['fields' => [
            'reference' => '2026. 04 286',
            'caterer' => 'BUTUAN SOUTHVILLE HOTEL AND RESTAURANT',
            'activity' => 'LUNCH PM SNACKS CONDUCT OF REGULAR MANAGEMENT COMMITTEE MEETING',
            'po_amount' => '117600.00',
        ]]);
    }

    public function test_scan_uploads_require_authentication_and_a_supported_document(): void
    {
        $this->postJson(route('purchase-orders.scan'))->assertUnauthorized();

        Process::fake();
        $this->actingAs(User::factory()->create())->post(route('purchase-orders.scan'), [
            'scan' => UploadedFile::fake()->create('scan.txt'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('scan');

        Process::assertNothingRan();
    }
}
