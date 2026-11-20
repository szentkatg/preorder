<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\OrderImport;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Currency;
use App\Models\ItemMainGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Season;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class OrderImportTest extends TestCase
{
    use RefreshDatabase;

    private PartnerUser $user;

    private Season $season;

    private array $orders = [];

    private Sku $sku;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('test', 8))]);
        $this->uploadRoot = sys_get_temp_dir().'/oi-'.bin2hex(random_bytes(4));
        Storage::set('tmp-for-tests', Storage::build(['driver' => 'local', 'root' => $this->uploadRoot]));
        $this->season = Season::create(['code' => 'W27', 'name' => 'Winter', 'visible_to_partners' => true]);
        $other = Season::create(['code' => 'S27', 'name' => 'Summer', 'visible_to_partners' => true]);
        $hidden = Season::create(['code' => 'W28', 'name' => 'Hidden']);
        session(['partner.locale' => 'hu', 'partner.season_id' => $this->season->id]);
        $brand = Brand::create(['code' => 'HT', 'name' => 'Heavy Tools']);
        $type = OrderSheetType::create(['code' => 'B', 'name' => 'Bags']);
        $currency = Currency::create(['code' => 'HUF', 'name' => 'Forint']);
        $list = PriceList::create(['code' => 'WHOLE', 'name' => 'Wholesale', 'type' => 'wholesale', 'currency_id' => $currency->id]);
        $group = ItemMainGroup::create(['code' => 'B01', 'name' => 'Bags']);
        $product = Product::create(['model_code' => 'MODEL', 'name_hu' => 'Model', 'season_id' => $this->season->id,
            'item_main_group_id' => $group->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id]);
        $color = Color::create(['product_id' => $product->id, 'code' => '01', 'name_hu' => 'BLACK']);
        $this->sku = Sku::create(['product_id' => $product->id, 'color_id' => $color->id,
            'sku_code' => 'MODEL01', 'sku_name' => 'Model BLACK', 'type' => 'piece']);
        PriceListItem::create(['price_list_id' => $list->id, 'season_id' => $this->season->id, 'product_id' => $product->id, 'net_price' => 100]);
        foreach (['a', 'b', 'denied'] as $key) {
            $partner = Partner::create(['erp_partner_code' => strtoupper($key), 'name' => 'Partner '.$key]);
            $address = PartnerAddress::create(['partner_id' => $partner->id, 'addrid' => '000'.$key, 'name' => 'Address '.$key]);
            $this->orders[$key] = Order::create(['partner_id' => $partner->id, 'partner_address_id' => $address->id,
                'season_id' => $this->season->id, 'brand_id' => $brand->id, 'order_sheet_type_id' => $type->id,
                'reference_number' => 'REF-'.$key, 'price_list_id' => $list->id, 'currency_id' => $currency->id, 'status' => 'draft']);
            OrderItem::create(['order_id' => $this->orders[$key]->id, 'sku_id' => $this->sku->id,
                'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);
        }
        foreach (['other' => $other, 'hidden' => $hidden] as $key => $season) {
            $order = $this->orders['a']->replicate();
            $order->season_id = $season->id;
            $order->save();
            $this->orders[$key] = $order;
        }
        $submitted = $this->orders['a']->replicate();
        $submitted->reference_number = 'SUBMITTED';
        $submitted->status = 'submitted';
        $submitted->save();
        $this->orders['submitted'] = $submitted;
        $this->user = PartnerUser::create(['partner_id' => $this->orders['a']->partner_id, 'role' => 'sales_rep',
            'email' => 'import@example.test', 'name' => 'Rep', 'password' => 'password', 'active' => true]);
        $this->user->partners()->attach([$this->orders['a']->partner_id, $this->orders['b']->partner_id]);
        $this->actingAs($this->user, 'partner');
    }

    protected function tearDown(): void
    {
        Storage::disk('tmp-for-tests')->deleteDirectory('livewire-tmp');
        if (is_dir($this->uploadRoot)) {
            rmdir($this->uploadRoot);
        }
        parent::tearDown();
    }

    private function upload(string $key = 'a', ?string $reference = null, int $quantity = 7): UploadedFile
    {
        $order = $this->orders[$key];
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        foreach (['C3' => $order->partnerAddress->addrid, 'C5' => $order->season->code,
            'C6' => 'HT', 'C7' => 'B', 'F1' => $reference ?? '',
            'A9' => json_encode(['version' => 1, 'type' => 'item', 'skus' => [4 => $this->sku->id]])] as $cell => $value) {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
        $sheet->setCellValue('D9', $quantity);
        $path = tempnam(sys_get_temp_dir(), 'oi-');
        try {
            IOFactory::createWriter($book, 'Xlsx')->save($path);

            return UploadedFile::fake()->createWithContent($key.'.xlsx', file_get_contents($path));
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_missing_references_can_be_completed_independently_without_changing_or_reuploading_files(): void
    {
        $a = $this->upload('a');
        $b = $this->upload('b', quantity: 9);
        $hashes = [hash_file('sha256', $a->getRealPath()), hash_file('sha256', $b->getRealPath())];
        $page = Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$a, $b])
            ->call('previewExcelImports')->assertSet('selectedImportPreviewKeys', [])
            ->assertSet('importPreviews.files.0.missing_reference_number', true)
            ->assertSet('importPreviews.files.1.missing_reference_number', true)
            ->assertSee('F1')->assertSee('importReferenceNumbers.file-0', false)->assertSee('importReferenceNumbers.file-1', false)
            ->assertDispatched('order-import-feedback');
        $this->assertDatabaseCount('order_import_logs', 0);
        $page->set('importReferenceNumbers.file-0', ' REF-a ')
            ->assertSet('importPreviews.files.0.success', true)
            ->assertSet('importPreviews.files.0.reference_number', 'REF-a')
            ->assertSet('importPreviews.files.0.stats.existing_quantity', 2)
            ->assertSet('importPreviews.files.0.stats.new_quantity', 7)
            ->assertSet('importPreviews.files.1.success', false)
            ->assertSet('selectedImportPreviewKeys', ['file-0']);
        $page->set('importReferenceNumbers.file-1', 'REF-b')->assertSet('importPreviews.success', true)
            ->assertSet('selectedImportPreviewKeys', ['file-0', 'file-1']);
        $this->assertSame([2, 2, 2], OrderItem::orderBy('id')->pluck('quantity')->all());
        foreach ($page->get('excelFiles') as $index => $file) {
            $this->assertSame($hashes[$index], hash_file('sha256', $file->getRealPath()));
            $book = IOFactory::load($file->getRealPath());
            $this->assertSame('', (string) $book->getActiveSheet()->getCell('F1')->getValue());
            $book->disconnectWorksheets();
        }
        $page->call('importSelectedExcelFiles')->assertSet('importResults.success', true)
            ->assertSet('importResults.files.0.reference_number', 'REF-a')
            ->assertSet('importResults.files.1.reference_number', 'REF-b')
            ->assertSet('importReferenceNumbers', [])->assertDispatched('order-summary-changed');
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['a']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['b']->id, 'quantity' => 9]);
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['denied']->id, 'quantity' => 2]);
        $this->assertDatabaseHas('order_import_logs', ['order_id' => $this->orders['b']->id,
            'partner_id' => $this->orders['b']->partner_id, 'status' => 'success']);
        $this->assertDatabaseCount('orders', 6);
    }

    public function test_invalid_reference_stays_editable_and_is_removed_from_selection(): void
    {
        $page = Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$this->upload()])
            ->call('previewExcelImports')->set('importReferenceNumbers.file-0', 'REF-a')
            ->assertSet('selectedImportPreviewKeys', ['file-0']);
        $page->set('importReferenceNumbers.file-0', 'DOES-NOT-EXIST')
            ->assertSet('importPreviews.files.0.success', false)->assertSet('selectedImportPreviewKeys', [])
            ->assertSee('importReferenceNumbers.file-0', false);
        $page->set('importReferenceNumbers.file-0', str_repeat('x', 101))->assertHasErrors(['importReferenceNumbers.file-0' => 'max']);
        $page->set('importReferenceNumbers.file-0', '')->assertHasNoErrors()
            ->assertSet('importPreviews.files.0.success', false)->assertSee('F1');
        $this->assertDatabaseCount('order_import_logs', 0);
        $this->assertDatabaseCount('orders', 6);
    }

    public function test_a_reference_already_in_f1_cannot_be_overridden(): void
    {
        Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$this->upload('a', 'REF-a')])
            ->call('previewExcelImports')->assertSet('importPreviews.files.0.missing_reference_number', false)
            ->assertDontSee('importReferenceNumbers.file-0', false)
            ->set('importReferenceNumbers.file-0', 'SUBMITTED')
            ->assertSet('importPreviews.files.0.reference_number', 'REF-a')
            ->call('importSelectedExcelFiles')->assertSet('importResults.success', true);
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['a']->id, 'quantity' => 7]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $this->orders['submitted']->id]);
    }

    public function test_import_rejects_inaccessible_other_season_hidden_and_submitted_targets(): void
    {
        $page = Livewire::test(OrderImport::class)->call('open')
            ->set('excelFiles', [$this->upload('denied'), $this->upload('other'), $this->upload('hidden'), $this->upload('submitted')])
            ->call('previewExcelImports');
        foreach (['REF-denied', 'REF-a', 'REF-a', 'SUBMITTED'] as $index => $reference) {
            $page->set('importReferenceNumbers.file-'.$index, $reference)
                ->assertSet('importPreviews.files.'.$index.'.success', false);
        }
        $page->assertSet('selectedImportPreviewKeys', []);
        // Even a tampered selection is revalidated at execution.
        $page->set('selectedImportPreviewKeys', ['file-0', 'file-1', 'file-2', 'file-3'])
            ->call('importSelectedExcelFiles')->assertSet('importResults.success', false);
        $this->assertSame([2, 2, 2], OrderItem::orderBy('id')->pluck('quantity')->all());
        $this->assertDatabaseCount('orders', 6);
    }

    public function test_address_users_cannot_import_other_addresses_and_access_is_rechecked_after_preview(): void
    {
        $this->user->update(['role' => 'address_user']);
        $this->user->addresses()->attach($this->orders['a']->partner_address_id);
        $page = Livewire::test(OrderImport::class)->call('open')
            ->set('excelFiles', [$this->upload('a'), $this->upload('b')])->call('previewExcelImports')
            ->set('importReferenceNumbers.file-0', 'REF-a')->assertSet('importPreviews.files.0.success', true)
            ->set('importReferenceNumbers.file-1', 'REF-b')->assertSet('importPreviews.files.1.success', false);
        $this->user->addresses()->detach();
        $page->call('importSelectedExcelFiles')->assertSet('importResults.success', false);
        $this->assertSame([2, 2, 2], OrderItem::orderBy('id')->pluck('quantity')->all());
    }

    public function test_submitted_status_is_rechecked_at_import_and_new_upload_or_close_clears_reference_overrides(): void
    {
        $page = Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$this->upload()])
            ->call('previewExcelImports')->set('importReferenceNumbers.file-0', 'REF-a');
        $this->orders['a']->update(['status' => 'submitted']);
        $page->call('importSelectedExcelFiles')->assertSet('importResults.success', false);
        $this->assertSame(2, $this->orders['a']->items()->first()->quantity);
        $page->set('importReferenceNumbers.file-0', 'OLD')->set('excelFiles', [$this->upload('b')])
            ->assertSet('importReferenceNumbers', [])->assertSet('importPreviews', []);
        $page->set('importReferenceNumbers.file-0', 'OLD')->call('close')->assertSet('showImportModal', false)
            ->assertSet('excelFiles', [])->assertSet('importReferenceNumbers', []);
    }

    public function test_import_modal_is_translated_and_can_be_opened_from_an_event(): void
    {
        session(['partner.locale' => 'en']);
        Livewire::test(OrderImport::class)->dispatch('open-order-import')->assertSet('showImportModal', true)
            ->assertSee('Import orders')->assertSee('Close')->assertSee('W27')->assertDontSee('partner.close');
    }

    public function test_the_order_sheet_has_the_same_reference_fields_but_keeps_its_existing_partner_scope(): void
    {
        Livewire::test(CatalogGroupOrder::class, ['order' => $this->orders['a'], 'catalogGroupName' => 'Bags'])
            ->set('excelFiles', [$this->upload('a'), $this->upload('b')])->call('previewExcelImports')
            ->assertSee('importReferenceNumbers.file-0', false)->assertDispatched('order-import-feedback')
            ->set('importReferenceNumbers.file-0', 'REF-a')->assertSet('importPreviews.files.0.success', true)
            ->set('importReferenceNumbers.file-1', 'REF-b')->assertSet('importPreviews.files.1.success', false)
            ->call('importSelectedExcelFiles')->assertSet('importResults.success', true);
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['a']->id, 'quantity' => 7]);
        $this->assertDatabaseHas('order_items', ['order_id' => $this->orders['b']->id, 'quantity' => 2]);
    }

    public function test_partner_admin_can_only_import_own_partners_orders(): void
    {
        $this->user->update(['role' => 'partner_admin']);
        Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$this->upload('a'), $this->upload('b')])
            ->call('previewExcelImports')->set('importReferenceNumbers.file-0', 'REF-a')
            ->assertSet('importPreviews.files.0.success', true)
            ->set('importReferenceNumbers.file-1', 'REF-b')->assertSet('importPreviews.files.1.success', false);
    }

    public function test_editing_one_reference_preserves_other_rows_and_their_manual_selection(): void
    {
        $page = Livewire::test(OrderImport::class)->call('open')->set('excelFiles', [$this->upload('a'), $this->upload('b')])
            ->call('previewExcelImports')->set('importReferenceNumbers.file-0', 'REF-a')
            ->set('importReferenceNumbers.file-1', 'REF-b')->call('clearSelectedImportPreviews');
        $otherPreview = $page->get('importPreviews.files.1');
        $page->set('importReferenceNumbers.file-0', 'NO-ORDER')->assertSet('selectedImportPreviewKeys', [])
            ->assertSet('importPreviews.files.1', $otherPreview);
        $page->set('importReferenceNumbers.file-0', 'REF-a')->assertSet('selectedImportPreviewKeys', ['file-0'])
            ->assertSet('importPreviews.files.1', $otherPreview);
    }
}
