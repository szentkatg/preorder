<?php

namespace Tests\Feature\Partner;

use App\Livewire\Partner\AddressShow;
use App\Livewire\Partner\OrderSummary as OrderSummaryPage;
use App\Livewire\Partner\PartnerOrderCoverage;
use App\Livewire\Partner\ProductList;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderSheetType;
use App\Models\Partner;
use App\Models\PartnerAddress;
use App\Models\PartnerUser;
use App\Models\Season;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PDO;
use Tests\TestCase;

class PartnerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        parent::setUp();

        $this->createSchema();
    }

    public function test_each_partner_role_only_receives_its_assigned_scope(): void
    {
        $data = $this->createAccessFixture();

        $partnerAdmin = $this->createPartnerUser(
            $data['partners']['a'],
            PartnerUser::ROLE_PARTNER_ADMIN,
            'admin@example.test'
        );

        $addressUser = $this->createPartnerUser(
            $data['partners']['a'],
            PartnerUser::ROLE_ADDRESS_USER,
            'address@example.test'
        );
        $addressUser->addresses()->attach($data['addresses']['a2']);

        $salesRep = $this->createPartnerUser(
            $data['partners']['c'],
            PartnerUser::ROLE_SALES_REP,
            'sales@example.test'
        );
        $salesRep->partners()->attach($data['partners']['a']);
        $salesRep->addresses()->attach($data['addresses']['d1']);

        $this->assertSameIds(
            $partnerAdmin->accessibleAddressesQuery()->get(),
            [$data['addresses']['a1']->id, $data['addresses']['a2']->id]
        );
        $this->assertSameIds(
            $partnerAdmin->accessibleOrdersQuery()->get(),
            [$data['orders']['a1']->id, $data['orders']['a2']->id]
        );

        $this->assertSameIds(
            $addressUser->accessibleAddressesQuery()->get(),
            [$data['addresses']['a2']->id]
        );
        $this->assertSameIds(
            $addressUser->accessibleOrdersQuery()->get(),
            [$data['orders']['a2']->id]
        );

        $this->assertSameIds(
            $salesRep->accessiblePartnersQuery()->get(),
            [
                $data['partners']['a']->id,
                $data['partners']['b']->id,
                $data['partners']['d']->id,
            ]
        );
        $this->assertSameIds(
            $salesRep->accessibleAddressesQuery()->get(),
            [
                $data['addresses']['a1']->id,
                $data['addresses']['a2']->id,
                $data['addresses']['b1']->id,
                $data['addresses']['d1']->id,
            ]
        );
        $this->assertSameIds(
            $salesRep->accessibleOrdersQuery()->get(),
            [
                $data['orders']['a1']->id,
                $data['orders']['a2']->id,
                $data['orders']['b1']->id,
                $data['orders']['d1']->id,
            ]
        );
    }

    public function test_address_user_cannot_open_or_create_an_order_for_another_address(): void
    {
        $data = $this->createAccessFixture();

        $addressUser = $this->createPartnerUser(
            $data['partners']['a'],
            PartnerUser::ROLE_ADDRESS_USER,
            'address@example.test'
        );
        $addressUser->addresses()->attach($data['addresses']['a1']);

        $this->actingAs($addressUser, 'partner');

        Livewire::test(AddressShow::class, [
            'season' => $data['season'],
            'address' => $data['addresses']['b1'],
        ])->assertForbidden();

        $orderCount = Order::count();

        Livewire::test(ProductList::class, [
            'season' => $data['season'],
            'address' => $data['addresses']['b1'],
            'brand' => $data['brand'],
            'type' => $data['orderSheetType'],
        ])->assertForbidden();

        $this->assertSame($orderCount, Order::count());

        Livewire::withQueryParams([
            'orders' => (string) $data['orders']['b1']->id,
        ])->test(OrderSummaryPage::class, [
            'season' => $data['season'],
            'brand' => $data['brand'],
            'orderSheetType' => $data['orderSheetType'],
        ])->assertForbidden();

        Livewire::test(PartnerOrderCoverage::class)
            ->assertForbidden();
    }

    public function test_sales_rep_inherits_the_portfolio_of_an_assigned_representative(): void
    {
        $data = $this->createAccessFixture();

        $salesRep = $this->createPartnerUser(
            $data['partners']['d'],
            PartnerUser::ROLE_SALES_REP,
            'delegated-sales@example.test'
        );
        $salesRep->partners()->attach($data['partners']['c']);

        $this->assertSameIds(
            $salesRep->accessiblePartnersQuery()->get(),
            [
                $data['partners']['b']->id,
                $data['partners']['c']->id,
            ]
        );
        $this->assertSameIds(
            $salesRep->accessibleAddressesQuery()->get(),
            [
                $data['addresses']['b1']->id,
                $data['addresses']['c1']->id,
            ]
        );
        $this->assertSameIds(
            $salesRep->accessibleOrdersQuery()->get(),
            [
                $data['orders']['b1']->id,
                $data['orders']['c1']->id,
            ]
        );
    }

    public function test_new_partners_addresses_and_orders_are_inherited_by_every_assigned_user_without_copying_links(): void
    {
        $data = $this->createAccessFixture();
        $users = [
            $this->createPartnerUser($data['partners']['d'], PartnerUser::ROLE_SALES_REP, 'first-rep@example.test'),
            $this->createPartnerUser($data['partners']['d'], PartnerUser::ROLE_SALES_REP, 'second-rep@example.test'),
        ];
        foreach ($users as $user) {
            $user->partners()->attach($data['partners']['c']);
            $user->load('partners'); // A previously loaded relation must not freeze the portfolio.
            $this->assertTrue($user->canAccessPartner($data['partners']['b']));
            $this->assertFalse($user->canAccessPartner($data['partners']['a']));
        }
        for ($i = 1; $i <= 10; $i++) {
            $partner = Partner::create(['erp_partner_code' => 'NEW-'.$i, 'name' => 'Új partner '.$i,
                'sales_rep_erp_partner_code' => 'C', 'active' => true]);
            $firstAddress = $this->createAddress($partner, 'NEW-'.$i.'-1');
            $secondAddress = $this->createAddress($partner, 'NEW-'.$i.'-2');
            $order = $this->createOrder($firstAddress, $data['season'], $data['brand'], $data['orderSheetType']);
            foreach ($users as $user) {
                $this->assertTrue($user->canAccessPartner($partner));
                $this->assertTrue($user->canAccessAddress($firstAddress));
                $this->assertTrue($user->canAccessAddress($secondAddress));
                $this->assertTrue($user->canAccessOrder($order));
            }
        }
        foreach ($users as $user) {
            $this->assertSame(1, $user->partners()->count());
            $this->assertSame(0, $user->addresses()->count());
            $this->assertSame(12, $user->accessiblePartnersQuery()->count()); // Representative C, existing B, ten new partners.
        }
    }

    public function test_reassignment_and_revocation_are_immediate_but_explicit_partner_grants_remain(): void
    {
        $data = $this->createAccessFixture();
        $inherited = $this->createPartnerUser($data['partners']['d'], PartnerUser::ROLE_SALES_REP, 'inherited@example.test');
        $explicit = $this->createPartnerUser($data['partners']['d'], PartnerUser::ROLE_SALES_REP, 'explicit@example.test');
        $inherited->partners()->attach($data['partners']['c']);
        $explicit->partners()->attach([$data['partners']['c']->id, $data['partners']['b']->id]);
        $this->assertTrue($inherited->canAccessOrder($data['orders']['b1']));
        $data['partners']['b']->update(['sales_rep_erp_partner_code' => 'A']);
        $this->assertFalse($inherited->canAccessPartner($data['partners']['b']));
        $this->assertFalse($inherited->canAccessAddress($data['addresses']['b1']));
        $this->assertFalse($inherited->canAccessOrder($data['orders']['b1']));
        $this->assertTrue($explicit->canAccessOrder($data['orders']['b1']));
        foreach ([$inherited, $explicit] as $user) {
            $user->partners()->detach($data['partners']['c']);
            $this->assertFalse($user->canAccessOrder($data['orders']['c1']));
        }
        $this->assertTrue($explicit->canAccessOrder($data['orders']['b1']));
    }

    public function test_other_roles_do_not_inherit_a_representative_portfolio_from_the_pivot(): void
    {
        $data = $this->createAccessFixture();
        foreach ([PartnerUser::ROLE_PARTNER_ADMIN, PartnerUser::ROLE_ADDRESS_USER] as $role) {
            $user = $this->createPartnerUser($data['partners']['a'], $role, $role.'@example.test');
            $user->partners()->attach($data['partners']['c']);
            $this->assertFalse($user->canAccessPartner($data['partners']['b']));
            $this->assertFalse($user->canAccessOrder($data['orders']['b1']));
        }
    }

    public function test_empty_erp_codes_do_not_grant_a_portfolio_of_unassigned_partners(): void
    {
        $data = $this->createAccessFixture();
        $user = $this->createPartnerUser($data['partners']['d'], PartnerUser::ROLE_SALES_REP, 'empty-code@example.test');
        $user->partners()->attach($data['partners']['a']);
        foreach (['', '  '] as $blankCode) {
            $data['partners']['a']->update(['erp_partner_code' => $blankCode]);
            $data['partners']['b']->update(['sales_rep_erp_partner_code' => $blankCode]);
            $this->assertTrue($user->canAccessPartner($data['partners']['a']));
            $this->assertFalse($user->canAccessPartner($data['partners']['b']));
            $this->assertFalse($user->canAccessOrder($data['orders']['b1']));
        }
    }

    private function createAccessFixture(): array
    {
        $partners = [
            'a' => Partner::query()->create([
                'name' => 'Partner A',
                'erp_partner_code' => 'A',
                'active' => true,
            ]),
            'b' => Partner::query()->create([
                'name' => 'Partner B',
                'erp_partner_code' => 'B',
                'sales_rep_erp_partner_code' => 'C',
                'active' => true,
            ]),
            'c' => Partner::query()->create([
                'name' => 'Partner C',
                'erp_partner_code' => 'C',
                'active' => true,
            ]),
            'd' => Partner::query()->create([
                'name' => 'Partner D',
                'erp_partner_code' => 'D',
                'active' => true,
            ]),
        ];

        $addresses = [
            'a1' => $this->createAddress($partners['a'], 'A1'),
            'a2' => $this->createAddress($partners['a'], 'A2'),
            'b1' => $this->createAddress($partners['b'], 'B1'),
            'c1' => $this->createAddress($partners['c'], 'C1'),
            'd1' => $this->createAddress($partners['d'], 'D1'),
        ];

        $season = Season::query()->create([
            'name' => 'Test season',
            'code' => 'TEST',
            'active' => true,
            'visible_to_partners' => true,
        ]);
        $brand = Brand::query()->create([
            'name' => 'Test brand',
            'code' => 'TEST',
            'active' => true,
        ]);
        $orderSheetType = OrderSheetType::query()->create([
            'name' => 'Test type',
            'code' => 'TEST',
            'active' => true,
        ]);

        foreach ($addresses as $address) {
            $address->brands()->attach($brand);
            $address->orderSheetTypes()->attach($orderSheetType);
        }

        $orders = [
            'a1' => $this->createOrder($addresses['a1'], $season, $brand, $orderSheetType),
            'a2' => $this->createOrder($addresses['a2'], $season, $brand, $orderSheetType),
            'b1' => $this->createOrder($addresses['b1'], $season, $brand, $orderSheetType),
            'c1' => $this->createOrder($addresses['c1'], $season, $brand, $orderSheetType),
            'd1' => $this->createOrder($addresses['d1'], $season, $brand, $orderSheetType),
        ];

        return compact(
            'partners',
            'addresses',
            'season',
            'brand',
            'orderSheetType',
            'orders'
        );
    }

    private function createSchema(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('erp_partner_code')->nullable();
            $table->string('sales_rep_erp_partner_code')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('partner_addresses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->string('addrid');
            $table->string('name');
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('partner_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_id');
            $table->string('role');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('partner_user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_user_id');
            $table->unsignedBigInteger('partner_address_id');
            $table->timestamps();
        });

        Schema::create('partner_user_partners', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('partner_user_id');
            $table->unsignedBigInteger('partner_id');
            $table->timestamps();
        });

        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('active')->default(true);
            $table->boolean('visible_to_partners')->default(false);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_sheet_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('partner_address_brand', function (Blueprint $table): void {
            $table->unsignedBigInteger('partner_address_id');
            $table->unsignedBigInteger('brand_id');
        });

        Schema::create('partner_address_order_sheet_type', function (Blueprint $table): void {
            $table->unsignedBigInteger('partner_address_id');
            $table->unsignedBigInteger('order_sheet_type_id');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('partner_address_id');
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('order_sheet_type_id');
            $table->string('status')->default('editing');
            $table->timestamps();
        });
    }

    private function createAddress(
        Partner $partner,
        string $code
    ): PartnerAddress {
        return PartnerAddress::query()->create([
            'partner_id' => $partner->id,
            'addrid' => $code,
            'name' => $code,
            'active' => true,
        ]);
    }

    private function createPartnerUser(
        Partner $partner,
        string $role,
        string $email
    ): PartnerUser {
        return PartnerUser::query()->create([
            'partner_id' => $partner->id,
            'role' => $role,
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'active' => true,
        ]);
    }

    private function createOrder(
        PartnerAddress $address,
        Season $season,
        Brand $brand,
        OrderSheetType $orderSheetType
    ): Order {
        return Order::query()->create([
            'season_id' => $season->id,
            'partner_id' => $address->partner_id,
            'partner_address_id' => $address->id,
            'brand_id' => $brand->id,
            'order_sheet_type_id' => $orderSheetType->id,
            'status' => 'editing',
        ]);
    }

    /** @param  array<int, int>  $expectedIds */
    private function assertSameIds(Collection $models, array $expectedIds): void
    {
        $actualIds = $models->modelKeys();

        sort($actualIds);
        sort($expectedIds);

        $this->assertSame($expectedIds, $actualIds);
    }
}
