<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NoticeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_can_save_a_notice_with_a_private_document(): void
    {
        Storage::fake('local');
        $dashboard = route('dashboard', ['view' => 'ntc']);
        $this->post(route('notices.store'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get($dashboard)
            ->assertOk()->assertSee('+ New NTC Entry');

        $this->post(route('notices.store'), [
            'dtrack' => 'TRACK-123',
            'reference' => 'NTC-123',
            'control_number' => 'CTRL-001',
            'time' => '09:30',
            'po_number' => 'PO-123',
            'caterer' => 'Example Supplier',
            'activity' => 'Staff workshop',
            'ntc_amount' => '1500.25',
            'scan' => UploadedFile::fake()->image('notice.png'),
        ])->assertRedirect($dashboard);

        $notice = DB::table('catering_records')->where('kind', 'ntc')->sole();
        $details = json_decode($notice->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('CTRL-001', $details['CONTROL NO. (S)']);
        Storage::disk('local')->assertExists($details['Scanned NTC storage path']);
        $document = route('notices.document', $notice->id);
        $preview = route('notices.document.preview', $notice->id);
        $this->get($dashboard)->assertOk()
            ->assertSee(['NTC entry saved.', 'TRACK-123', 'NTC-123', '09:30', 'Example Supplier', 'PO-123', '₱1,500.25', 'notice.png', $preview, $document])
            ->assertDontSee($details['Scanned NTC storage path']);
        $this->get($preview)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename=scanned-ntc.png');
        $this->get($document)->assertOk()->assertDownload('scanned-ntc.png');

        $update = route('notices.update', $notice->id);
        $this->patch($update, ['reference' => 'NTC-124', 'control_number' => 'CTRL-002'])->assertRedirect($dashboard);
        $this->get($dashboard)->assertOk()
            ->assertSee(['NTC entry updated.', 'Edit NTC entry NTC-124', 'notice.png', 'Control No.', 'CTRL-002'])
            ->assertSee('<td class="record-value">CTRL-002</td>', false);
        Storage::disk('local')->assertExists($details['Scanned NTC storage path']);

        $this->post($update, [
            '_method' => 'PATCH',
            'reference' => 'NTC-124',
            'scan' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertRedirect($dashboard);
        $updated = DB::table('catering_records')->where('kind', 'ntc')->sole();
        $updatedDetails = json_decode($updated->details, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('TRACK-123', $updatedDetails['DTRACK (A)']);
        $this->assertSame('CTRL-002', $updatedDetails['CONTROL NO. (S)']);
        $this->assertSame('replacement.jpg', $updatedDetails['SCANNED NTC (P)']);
        Storage::disk('local')->assertMissing($details['Scanned NTC storage path']);
        $this->get($document)->assertOk()->assertDownload('scanned-ntc.jpg');

        auth()->logout();
        $this->get($document)->assertRedirect(route('login'));
        $this->get($preview)->assertRedirect(route('login'));
    }

    public function test_invalid_notice_data_reopens_the_form(): void
    {
        $dashboard = route('dashboard', ['view' => 'ntc']);

        $this->actingAs(User::factory()->create())->from($dashboard)->followingRedirects()
            ->post(route('notices.store'), [
                'date_received' => 'invalid',
                'scan' => UploadedFile::fake()->create('notice.txt', 1, 'text/plain'),
            ])
            ->assertOk()->assertSee(['data-auto-open', 'ntc-error-reference', 'ntc-error-date_received', 'ntc-error-scan']);

        $this->assertDatabaseEmpty('catering_records');
    }

    public function test_notice_table_displays_workbook_columns_in_order(): void
    {
        $noticeId = DB::table('catering_records')->insertGetId([
            'kind' => 'ntc',
            'source_file' => 'notice.xlsx',
            'source_sheet' => 'Notices',
            'source_row' => 3,
            'reference' => 'NTC-2026-001',
            'caterer' => 'Sample Caterer',
            'activity' => 'Staff workshop',
            'details' => json_encode([
                'DTRACK (A)' => 'TRACK-001',
                'TIME (D)' => '9:30 AM',
                'PO# NO. (F)' => 'PO-001',
                'NTC AMOUNT (J)' => '1200',
                'BILLED AMOUNT/ACTUAL (L)' => '1150.25',
                'SCANNED NTC (P)' => 'notice.pdf',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs(User::factory()->create())->get(route('dashboard', ['view' => 'ntc', 'q' => 'notice.pdf']))
            ->assertOk()
            ->assertSeeInOrder(['<th scope="col">NTC No.</th>', '<th scope="col">Control No.</th>', '<th scope="col">Caterer and activity</th>', '<th scope="col">Date Received</th>', '<th scope="col" class="numeric">NTC Amount</th>', '<th scope="col">Actions</th>'], false)
            ->assertDontSee('<th scope="col">DTrack No.</th>', false)
            ->assertSeeInOrder(['<dt>DTrack No.</dt>', '<dt>NTC No.</dt>', '<dt>Date Received</dt>', '<dt>Time</dt>', '<dt>Caterer/Supplier</dt>', '<dt>PO No.</dt>', '<dt>Scanned NTC Document</dt>'], false)
            ->assertSeeInOrder(['TRACK-001', 'NTC-2026-001', 'Sample Caterer', 'PO-001', 'Staff workshop', '₱1,200.00', '₱1,150.25', 'notice.pdf'])
            ->assertSee('value="9:30 AM"', false);

        $this->patch(route('notices.update', $noticeId), [
            'reference' => 'NTC-2026-001',
            'time' => '9:30 AM',
            'activity' => 'Updated workshop',
        ])->assertRedirect(route('dashboard', ['view' => 'ntc']));
        $this->assertDatabaseHas('catering_records', [
            'id' => $noticeId,
            'activity' => 'Updated workshop',
            'source_row' => 3,
        ]);
    }
}
