<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\OrderBulkSubmission;
use App\Livewire\Partner\OrderOverview;
use App\Livewire\Partner\OrderSelector;
use App\Livewire\Partner\SalesRepOrderSummary;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\OrderStatusLog;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Size;
use App\Models\Sku;
use App\Services\Partner\OrderSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BulkOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private PartnerUser $user;

    private PartnerAddress $address;

    private array $orders;

    private array $messages = [];

    private bool $mailFails = false;

    private int $excelExportCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8)), 'app.order_notification_cc' => 'office@example.test']);
        $season = Season::create(['code' => 'W27', 'name' => 'Winter', 'visible_to_partners' => true]);
        session(['partner.season_id' => $season->id, 'partner.locale' => 'hu']);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint']);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Wholesale', 'type' => 'wholesale', 'currency_id' => $currency->id]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools']);
        $type = OrderSheetType::create(['code' => 'B', 'name' => 'Bags']);
        $partner = Partner::create(['erp_partner_code' => 'P01', 'name' => 'Partner']);
        $this->address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => 'A01', 'name' => 'Shop']);
        $this->user = PartnerUser::create(['partner_id' => $partner->id, 'name' => 'Partner user', 'email' => 'user@example.test',
            'password' => 'password', 'role' => 'sales_rep', 'active' => true]);
        $this->user->partners()->attach($partner);
        $this->user->addresses()->attach($this->address);
        $this->actingAs($this->user, 'partner');
        foreach (['first' => 'draft', 'second' => 'editing', 'submitted' => 'submitted', 'unselected' => 'draft'] as $key => $status) {
            $this->orders[$key] = Order::create(['season_id' => $season->id, 'partner_id' => $partner->id,
                'partner_address_id' => $this->address->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id,
                'reference_number' => 'REF-'.$key, 'price_list_id' => $list->id, 'currency_id' => $currency->id,
                'status' => $status, 'submitted_at' => $status === 'submitted' ? now()->subDay() : null]);
        }
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Bags']);
        $product = Product::create(['visible_to_partners' => true, 'season_id' => $season->id, 'model_code' => 'B01W27',
            'name_hu' => 'MODEL', 'item_main_group_id' => $group->id, 'brand_id' => $brand->id,
            'order_sheet_type_id' => $type->id, 'catalog_group_name_hu' => 'Bags']);
        $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK']);
        $size = Size::create(['code' => 'ONE', 'name_hu' => 'ONE']);
        $sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id,
            'sku_code' => 'B01W2701', 'sku_name' => 'MODEL BLACK', 'type' => 'piece']);
        PriceListItem::create(['season_id' => $season->id, 'price_list_id' => $list->id, 'product_id' => $product->id, 'net_price' => 100]);
        foreach ($this->orders as $order) {
            OrderItem::create(['order_id' => $order->id, 'sku_id' => $sku->id, 'quantity' => 3, 'unit_price' => 100, 'line_total' => 300]);
        }
        Excel::shouldReceive('raw')->andReturnUsing(function (): string {
            $this->excelExportCount++;

            return 'xlsx-content';
        });
        Mail::shouldReceive('raw')->andReturnUsing(function ($body, $callback): void {
            if ($this->mailFails) {
                throw new RuntimeException('Mail unavailable');
            }
            $message = new Message(new Email);
            $callback($message);
            $this->messages[] = $message->getSymfonyMessage();
        });
    }

    private function page(array $keys = ['first', 'second', 'submitted'])
    {
        return Livewire::test(OrderBulkSubmission::class)
            ->set('selectedSummaryOrderIds', array_map(fn ($key) => (string) $this->orders[$key]->id, $keys));
    }

    public function test_order_selector_wires_the_button_to_the_isolated_submission_modal(): void
    {
        Livewire::test(OrderSelector::class)->assertSeeLivewire(OrderBulkSubmission::class);
        Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')
            ->assertSee('Több rendelés beküldése')->set('selectedSummaryOrderIds', [$this->orders['first']->id])
            ->call('openBulkSubmission')->assertDispatched('open-order-bulk-submission', orderIds: [$this->orders['first']->id]);
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame([], $this->messages);
    }

    public function test_completion_refreshes_statuses_and_selection_without_resetting_filters(): void
    {
        $page = Livewire::test(SalesRepOrderSummary::class)->call('loadSummary')
            ->set('summaryStatus', 'submitted')->set('summarySearch', 'REF-first')
            ->set('selectedSummaryOrderIds', [$this->orders['first']->id]);
        app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $page->dispatch('orders-bulk-submitted')->assertSet('summaryStatus', 'submitted')
            ->assertSet('summarySearch', 'REF-first')->assertSet('selectedSummaryOrderIds', []);
        $this->assertSame([$this->orders['first']->id], $page->instance()->filteredSalesRepOrderSummaries->pluck('order_id')->all());
    }

    public function test_confirmation_cancel_does_not_submit_or_send_mail(): void
    {
        $this->page()->call('openBulkSubmission')->assertSee('Több rendelés beküldése')
            ->assertSet('bulkSubmission.total', 3)->assertSet('bulkSubmission.already_submitted', 1)
            ->assertSeeHtml('data-bulk-order-submission')->call('processBulkSubmission')
            ->call('closeBulkSubmission')->assertSet('bulkSubmission', []);
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame([], $this->messages);
    }

    #[DataProvider('roles')]
    public function test_all_partner_roles_submit_only_selected_accessible_orders_with_confirmation_mail(string $role): void
    {
        $this->user->update(['role' => $role]);
        $oldTimestamp = $this->orders['submitted']->submitted_at;
        $beforeItems = OrderItem::orderBy('id')->get()->toArray();
        $page = $this->page()->call('openBulkSubmission')->call('startBulkSubmission')
            ->assertSet('bulkSubmission.processed', 2)->assertSet('bulkSubmission.running', true)
            ->call('processBulkSubmission')
            ->assertSet('bulkSubmission.running', false)->assertSet('bulkSubmission.submitted', 2)
            ->assertSet('bulkSubmission.skipped', 1)->assertSet('bulkSubmission.failed', [])
            ->assertSet('bulkSubmission.email_failed', [])->assertSet('selectedSummaryOrderIds', [])
            ->assertDispatched('orders-bulk-submitted');
        $this->assertSame('draft', $this->orders['unselected']->fresh()->status);
        foreach (['first', 'second'] as $key) {
            $this->assertSame('submitted', $this->orders[$key]->fresh()->status);
            $this->assertNotNull($this->orders[$key]->fresh()->submitted_at);
        }
        $this->assertEquals($oldTimestamp, $this->orders['submitted']->fresh()->submitted_at);
        $this->assertCount(2, $this->messages);
        $this->assertSame('user@example.test', $this->messages[0]->getTo()[0]->getAddress());
        $this->assertSame('office@example.test', $this->messages[0]->getCc()[0]->getAddress());
        $this->assertStringContainsString('REF-first', $this->messages[0]->getSubject());
        $this->assertCount(1, $this->messages[0]->getAttachments());
        $this->assertSame($beforeItems, OrderItem::orderBy('id')->get()->toArray());
        $page->call('processBulkSubmission');
        $this->assertCount(2, $this->messages);
    }

    public static function roles(): array
    {
        return [['partner_admin'], ['address_user'], ['sales_rep']];
    }

    public function test_confirmation_email_checkbox_defaults_on_and_resets_for_a_new_confirmation(): void
    {
        $this->page(['first'])->call('openBulkSubmission')->assertSet('sendConfirmationEmail', true)
            ->assertSeeHtml('wire:model="sendConfirmationEmail"')->assertSee('Visszaigazoló e-mail küldése')
            ->set('sendConfirmationEmail', false)->call('closeBulkSubmission')->call('openBulkSubmission')
            ->assertSet('sendConfirmationEmail', true);
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame([], $this->messages);
        $this->assertSame(0, $this->excelExportCount);
    }

    #[DataProvider('roles')]
    public function test_status_only_submission_never_creates_an_excel_file_or_sends_mail(string $role): void
    {
        $this->user->update(['role' => $role]);
        $this->mailFails = true;
        $beforeItems = OrderItem::orderBy('id')->get()->toArray();
        $submittedAt = $this->orders['submitted']->submitted_at;
        $this->page()->call('openBulkSubmission')->set('sendConfirmationEmail', false)->call('startBulkSubmission')
            ->assertSet('bulkSubmission.send_email', false)->call('processBulkSubmission')
            ->assertSet('bulkSubmission.submitted', 2)->assertSet('bulkSubmission.skipped', 1)
            ->assertSet('bulkSubmission.email_failed', [])->assertSet('bulkSubmission.failed', [])
            ->assertSee('Csak státuszállítás, e-mail-küldés nélkül.');
        foreach (['first', 'second'] as $key) {
            $this->assertSame('submitted', $this->orders[$key]->fresh()->status);
            $this->assertNotNull($this->orders[$key]->fresh()->submitted_at);
        }
        $this->assertSame('draft', $this->orders['unselected']->fresh()->status);
        $this->assertEquals($submittedAt, $this->orders['submitted']->fresh()->submitted_at);
        $this->assertSame($beforeItems, OrderItem::orderBy('id')->get()->toArray());
        $this->assertSame([], $this->messages);
        $this->assertSame(0, $this->excelExportCount);
    }

    #[DataProvider('emailChoices')]
    public function test_confirmed_email_choice_stays_fixed_for_later_processing_requests(bool $sendEmail): void
    {
        $this->page(['first', 'second'])->call('openBulkSubmission')->set('sendConfirmationEmail', $sendEmail)
            ->call('startBulkSubmission')->assertSet('bulkSubmission.running', true)
            ->set('sendConfirmationEmail', ! $sendEmail)->call('processBulkSubmission')
            ->assertSet('bulkSubmission.send_email', $sendEmail)->assertSet('bulkSubmission.submitted', 2);
        $this->assertCount($sendEmail ? 2 : 0, $this->messages);
        $this->assertSame($sendEmail ? 2 : 0, $this->excelExportCount);
    }

    public static function emailChoices(): array
    {
        return [[true], [false]];
    }

    public function test_browser_cannot_override_the_confirmed_email_snapshot(): void
    {
        $page = $this->page(['first', 'second'])->call('openBulkSubmission')->set('sendConfirmationEmail', false)
            ->call('startBulkSubmission');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('bulkSubmission.send_email', true);
    }

    public function test_status_only_service_call_is_idempotent_without_touching_email_or_export(): void
    {
        $service = app(OrderSubmissionService::class);
        $this->assertSame(['submitted' => true, 'email_failed' => false], $service->submit($this->orders['first']->id, sendEmail: false));
        $this->assertSame(['submitted' => false, 'email_failed' => false], $service->submit($this->orders['first']->id, sendEmail: false));
        $this->assertSame([], $this->messages);
        $this->assertSame(0, $this->excelExportCount);
    }

    #[DataProvider('emailChoices')]
    public function test_submission_audit_keeps_identity_ip_status_and_email_choice(bool $sendEmail): void
    {
        $this->freezeTime();
        $this->app->instance('request', Request::create('/partner/test', 'POST', server: [
            'REMOTE_ADDR' => '2001:db8::123', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
            'HTTP_AUTHORIZATION' => 'Bearer do-not-record-this',
        ]));
        app(OrderSubmissionService::class)->submit($this->orders['first']->id, sendEmail: $sendEmail);
        $this->assertDatabaseCount('order_status_logs', 1);
        $entry = OrderStatusLog::firstOrFail();
        $this->assertSame($this->orders['first']->id, $entry->order_id);
        $this->assertSame('REF-first', $entry->reference_number);
        $this->assertSame('P01', $entry->partner_code);
        $this->assertSame('Partner', $entry->partner_name);
        $this->assertSame('A01', $entry->address_code);
        $this->assertSame('W27', $entry->season_code);
        $this->assertSame($this->user->id, $entry->partner_user_id);
        $this->assertSame('Partner user', $entry->user_name);
        $this->assertSame('user@example.test', $entry->user_email);
        $this->assertSame('2001:db8::123', $entry->ip_address);
        $this->assertSame('submitted', $entry->event);
        $this->assertSame('single', $entry->source);
        $this->assertSame('draft', $entry->from_status);
        $this->assertSame('submitted', $entry->to_status);
        $this->assertSame(now()->utc()->format('Y-m-d H:i:s'), $entry->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame($sendEmail, $entry->email_requested);
        $this->assertSame($sendEmail ? 'sent' : 'not_requested', $entry->email_status);
        $sendEmail ? $this->assertNotNull($entry->email_finished_at) : $this->assertNull($entry->email_finished_at);
        $this->assertStringNotContainsString('do-not-record-this', $entry->toJson());
    }

    public function test_bulk_audit_records_only_real_transitions_and_labels_the_source(): void
    {
        $this->page()->call('openBulkSubmission')->set('sendConfirmationEmail', false)->call('startBulkSubmission')->call('processBulkSubmission');
        $this->assertDatabaseCount('order_status_logs', 2);
        $this->assertSame(['REF-first', 'REF-second'], OrderStatusLog::orderBy('id')->pluck('reference_number')->all());
        $this->assertSame(['bulk'], OrderStatusLog::distinct()->pluck('source')->all());
        $this->assertSame(['not_requested'], OrderStatusLog::distinct()->pluck('email_status')->all());
        $this->assertSame(['draft', 'editing'], OrderStatusLog::orderBy('id')->pluck('from_status')->all());
        app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $this->assertDatabaseCount('order_status_logs', 2);
    }

    public function test_reopening_preserves_history_across_resubmission_and_later_deletion(): void
    {
        $service = app(OrderSubmissionService::class);
        $order = $this->orders['first'];
        $service->submit($order->id, sendEmail: false);
        $service->reopen($order->id);
        $service->reopen($order->id);
        $service->submit($order->id);
        $this->assertSame(['submitted', 'reopened', 'submitted'], OrderStatusLog::orderBy('id')->pluck('event')->all());
        $entry = OrderStatusLog::where('event', 'reopened')->firstOrFail();
        $this->assertSame('submitted', $entry->from_status);
        $this->assertSame('draft', $entry->to_status);
        $this->assertFalse($entry->email_requested);
        $this->assertSame('not_applicable', $entry->email_status);
        $this->assertNull($entry->email_finished_at);
        $this->user->update(['name' => 'Changed name', 'email' => 'changed@example.test']);
        $this->user->delete();
        $order->items()->delete();
        $order->delete();
        $this->assertDatabaseCount('order_status_logs', 3);
        $this->assertSame('Partner user', $entry->fresh()->user_name);
        $this->assertSame('user@example.test', $entry->fresh()->user_email);
        $this->assertSame('REF-first', $entry->fresh()->reference_number);
    }

    public function test_email_failure_is_recorded_without_saving_exception_messages(): void
    {
        $this->mailFails = true;
        app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $entry = OrderStatusLog::firstOrFail();
        $this->assertSame('failed', $entry->email_status);
        $this->assertSame(RuntimeException::class, $entry->email_error_type);
        $this->assertNotNull($entry->email_finished_at);
        $this->assertStringNotContainsString('Mail unavailable', $entry->toJson());
        $this->assertSame('submitted', $this->orders['first']->fresh()->status);
    }

    public function test_invalid_ip_is_not_saved_and_missing_recipient_has_an_explicit_outcome(): void
    {
        $this->app->instance('request', Request::create('/partner/test', server: ['REMOTE_ADDR' => 'not-an-ip']));
        $this->user->update(['email' => '']);
        $result = app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $entry = OrderStatusLog::firstOrFail();
        $this->assertNull($entry->ip_address);
        $this->assertSame('no_recipient', $entry->email_status);
        $this->assertTrue($result['email_failed']);
        $this->assertSame(0, $this->excelExportCount);
        $this->assertSame([], $this->messages);
    }

    public function test_failed_audit_insert_rolls_back_submission_and_does_not_send_mail(): void
    {
        OrderStatusLog::creating(fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(OrderSubmissionService::class)->submit($this->orders['first']->id);
            $this->fail('Submission must not proceed without its audit record');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertNull($this->orders['first']->fresh()->submitted_at);
        $this->assertDatabaseCount('order_status_logs', 0);
        $this->assertSame([], $this->messages);
    }

    public function test_failed_audit_insert_also_rolls_back_reopening(): void
    {
        $order = $this->orders['submitted'];
        $oldTimestamp = $order->submitted_at;
        OrderStatusLog::creating(fn () => throw new RuntimeException('Audit unavailable'));
        try {
            app(OrderSubmissionService::class)->reopen($order->id);
            $this->fail('Reopening must not proceed without its audit record');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertSame('submitted', $order->fresh()->status);
        $this->assertEquals($oldTimestamp, $order->fresh()->submitted_at);
        $this->assertDatabaseCount('order_status_logs', 0);
    }

    public function test_failed_email_audit_update_keeps_unknown_outcome_without_mislabeling_sent_mail(): void
    {
        OrderStatusLog::updating(fn () => throw new RuntimeException('Audit update unavailable'));
        Log::shouldReceive('error')->once()->with('Order email audit result persistence failed', \Mockery::on(fn ($context) => array_keys($context) === ['audit_id', 'order_id', 'exception_type']));
        $result = app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $this->assertFalse($result['email_failed']);
        $this->assertCount(1, $this->messages);
        $entry = OrderStatusLog::firstOrFail();
        $this->assertSame('pending', $entry->email_status);
        $this->assertNull($entry->email_finished_at);
        $this->assertSame('submitted', $this->orders['first']->fresh()->status);
    }

    public function test_empty_selection_has_a_clear_validation_message(): void
    {
        $this->page([])->call('openBulkSubmission')->assertHasErrors('selectedSummaryOrderIds')
            ->assertSee('Jelölj ki legalább egy beküldendő rendelést.')->assertSet('bulkSubmission', []);
        $this->assertSame([], $this->messages);
    }

    #[DataProvider('invalidSelections')]
    public function test_denied_missing_or_other_season_ids_reject_the_whole_selection(string $invalid): void
    {
        $bad = $this->orders['unselected'];
        if ($invalid === 'missing') {
            $id = 999999;
        } elseif ($invalid === 'season') {
            $season = Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true]);
            $bad->update(['season_id' => $season->id]);
            $id = $bad->id;
        } else {
            $partner = Partner::create(['erp_partner_code' => 'DENIED', 'name' => 'Denied']);
            $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => 'DENIED', 'name' => 'Denied shop']);
            $bad->update(['partner_id' => $partner->id, 'partner_address_id' => $address->id]);
            $id = $bad->id;
        }
        $this->page(['first'])->set('selectedSummaryOrderIds', [$this->orders['first']->id, $id])
            ->call('openBulkSubmission')->assertForbidden();
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame([], $this->messages);
    }

    public static function invalidSelections(): array
    {
        return [['denied'], ['missing'], ['season']];
    }

    public function test_confirmation_uses_its_own_snapshot_not_a_later_table_selection(): void
    {
        $this->page(['first'])->call('openBulkSubmission')->set('selectedSummaryOrderIds', [$this->orders['second']->id])
            ->call('startBulkSubmission')->assertSet('bulkSubmission.submitted', 1);
        $this->assertSame('editing', $this->orders['second']->fresh()->status);
    }

    public function test_access_is_rechecked_at_confirmation_before_any_write(): void
    {
        $page = $this->page(['first'])->call('openBulkSubmission');
        $this->user->partners()->detach();
        $this->user->addresses()->detach();
        $page->call('startBulkSubmission')->assertForbidden();
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
    }

    public function test_address_user_cannot_submit_an_unassigned_address_even_for_the_same_partner(): void
    {
        $this->user->update(['role' => 'address_user']);
        $otherAddress = PartnerAddress::create(['partner_id' => $this->address->partner_id, 'addrid' => 'OTHER', 'name' => 'Other shop']);
        $this->orders['second']->update(['partner_address_id' => $otherAddress->id]);
        $this->page(['first', 'second'])->call('openBulkSubmission')->assertForbidden();
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame('editing', $this->orders['second']->fresh()->status);
        $this->assertSame([], $this->messages);
    }

    public function test_inactive_user_cannot_submit_even_with_a_stale_authenticated_model(): void
    {
        $page = $this->page(['first'])->call('openBulkSubmission');
        PartnerUser::whereKey($this->user->id)->update(['active' => false]);
        $page->call('startBulkSubmission')->assertForbidden();
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
    }

    public function test_repeated_service_call_does_not_resend_a_confirmation(): void
    {
        $service = app(OrderSubmissionService::class);
        $this->assertTrue($service->submit($this->orders['first']->id)['submitted']);
        $this->assertFalse($service->submit($this->orders['first']->id)['submitted']);
        $this->assertCount(1, $this->messages);
    }

    public function test_duplicate_selection_is_normalised_and_an_order_submitted_in_another_tab_is_skipped(): void
    {
        $page = $this->page()->set('selectedSummaryOrderIds', [$this->orders['first']->id, (string) $this->orders['first']->id])
            ->call('openBulkSubmission')->assertSet('bulkSubmission.total', 1);
        $this->orders['first']->update(['status' => 'submitted', 'submitted_at' => now()]);
        $page->call('startBulkSubmission')->assertSet('bulkSubmission.skipped', 1)->assertSet('bulkSubmission.submitted', 0);
        $this->assertSame([], $this->messages);
    }

    public function test_failed_order_is_listed_without_undoing_previous_submissions(): void
    {
        $page = $this->page(['first', 'second', 'submitted'])->call('openBulkSubmission')->call('startBulkSubmission');
        $this->orders['second']->items()->delete();
        $this->orders['second']->delete();
        $page->call('processBulkSubmission')->call('processBulkSubmission')
            ->assertSet('bulkSubmission.running', false)->assertSet('bulkSubmission.submitted', 1)
            ->assertSet('bulkSubmission.failed', ['REF-second'])->assertSet('bulkSubmission.skipped', 1);
        $this->assertSame('submitted', $this->orders['first']->fresh()->status);
        $this->assertCount(1, $this->messages);
    }

    public function test_stale_season_tab_cannot_start_submission(): void
    {
        $page = $this->page(['first'])->call('openBulkSubmission');
        $other = Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true]);
        session(['partner.season_id' => $other->id]);
        $page->call('startBulkSubmission')->assertNotFound();
        $this->assertSame('draft', $this->orders['first']->fresh()->status);
        $this->assertSame([], $this->messages);
    }

    public function test_server_rejects_closing_a_running_submission(): void
    {
        $this->page(['first', 'second'])->call('openBulkSubmission')->call('startBulkSubmission')
            ->call('closeBulkSubmission')->assertStatus(409);
        $this->assertSame('editing', $this->orders['second']->fresh()->status);
    }

    public function test_email_failure_does_not_undo_submission_and_the_next_order_still_runs(): void
    {
        $this->mailFails = true;
        $this->page(['first', 'second'])->call('openBulkSubmission')->call('startBulkSubmission')
            ->call('processBulkSubmission')->assertSet('bulkSubmission.submitted', 2)
            ->assertSet('bulkSubmission.email_failed', ['REF-first', 'REF-second'])
            ->assertSee('A rendelés beküldése sikerült, de a visszaigazoló e-mailt nem sikerült elküldeni.');
        $this->assertSame('submitted', $this->orders['second']->fresh()->status);
    }

    public function test_progress_and_target_ids_cannot_be_forged_in_the_browser(): void
    {
        $page = $this->page(['first'])->call('openBulkSubmission');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('bulkSubmission.orders.0.id', $this->orders['unselected']->id);
    }

    public function test_service_is_shared_with_single_order_submission(): void
    {
        Livewire::test(OrderOverview::class, ['order' => $this->orders['first']->id])
            ->call('submitSelectedOrder')->assertHasNoErrors()->call('submitSelectedOrder');
        $this->assertSame('submitted', $this->orders['first']->fresh()->status);
        $this->assertCount(1, $this->messages);
    }

    public function test_confirmation_contains_a_real_excel_file_with_the_correct_order_reference(): void
    {
        app()->forgetInstance('excel');
        Excel::clearResolvedInstance('excel');
        $result = app(OrderSubmissionService::class)->submit($this->orders['first']->id);
        $this->assertFalse($result['email_failed']);
        $attachment = $this->messages[0]->getAttachments()[0];
        $this->assertStringStartsWith('PK', $attachment->getBody());
        $this->assertStringEndsWith('.xlsx', $attachment->getFilename());
        $path = tempnam(sys_get_temp_dir(), 'bulk-submission-test-');
        $workbook = null;
        try {
            file_put_contents($path, $attachment->getBody());
            $workbook = IOFactory::load($path);
            $this->assertSame('REF-first', $workbook->getActiveSheet()->getCell('F1')->getValue());
        } finally {
            $workbook?->disconnectWorksheets();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_confirmation_also_works_without_an_optional_cc_address(): void
    {
        config(['app.order_notification_cc' => null]);
        $this->assertFalse(app(OrderSubmissionService::class)->submit($this->orders['first']->id)['email_failed']);
        $this->assertSame([], $this->messages[0]->getCc());
    }

    public function test_single_submission_reports_email_failure_without_claiming_submission_failed(): void
    {
        $this->mailFails = true;
        Livewire::test(OrderOverview::class, ['order' => $this->orders['first']->id])->call('submitSelectedOrder')
            ->assertHasErrors('orderSubmission')->assertSee('A rendelés beküldése sikerült');
        $this->assertSame('submitted', $this->orders['first']->fresh()->status);
    }

    public function test_confirm_dialog_and_results_follow_the_selected_language(): void
    {
        session(['partner.locale' => 'en']);
        $this->page(['submitted'])->call('openBulkSubmission')->assertSee('Submit multiple orders')
            ->assertSee('Send confirmation email')->assertSee('When unchecked, only the status and submission time change')
            ->assertSee('They cannot be edited after submission.')->call('startBulkSubmission')
            ->assertSet('bulkSubmission.processed', 1)->assertSet('bulkSubmission.running', false)
            ->assertSee('Already submitted, skipped: 1.');
        $this->assertSame([], $this->messages);
    }
}
