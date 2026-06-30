<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use App\Livewire\Partner\Auth\Login;
use App\Livewire\Partner\Auth\ForgotPassword;
use App\Livewire\Partner\Auth\ResetPassword;
use App\Livewire\Partner\Dashboard;
use App\Livewire\Partner\SeasonShow;
use App\Livewire\Partner\AddressShow;
use App\Livewire\Partner\BrandShow;
use App\Livewire\Partner\ProductList;
use App\Livewire\Partner\CatalogGroupOrder;
use App\Livewire\Partner\OrderSelector;
use App\Livewire\Partner\OrderSummary;
use App\Livewire\Partner\PartnerOrderCoverage;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/partner/logout', function (Request $request) {
    Auth::guard('partner')->logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('partner.login');
})->name('partner.logout');

Route::get('/login', function () {
    return redirect()->route('partner.login');
})->name('login');

Route::get('/reset-password/{token}', function (string $token) {
    return redirect()->route('partner.password.reset', [
        'token' => $token,
        'email' => request('email'),
    ]);
})->name('password.reset');

Route::prefix('partner')
    ->name('partner.')
    ->group(function () {
        Route::get('/login', Login::class)
            ->name('login');

        Route::get('/forgot-password', ForgotPassword::class)
            ->name('password.request');

        Route::get('/reset-password/{token}', ResetPassword::class)
            ->name('password.reset');

        Route::middleware('auth:partner')
            ->group(function () {
                Route::get('/orders/select', OrderSelector::class)
                    ->name('orders.select');

                Route::get('/order-coverage', PartnerOrderCoverage::class)
                    ->name('order-coverage');

                Route::get('/orders/summary/{season}/{brand}/{orderSheetType}', OrderSummary::class)
                    ->name('orders.summary');

                Route::get('/dashboard', Dashboard::class)
                    ->name('dashboard');

                Route::get(
                    '/seasons/{season}/addresses/{address}/brands/{brand}/types/{type}',
                    ProductList::class
                )->name('products.index');

                Route::get(
                    '/orders/{order}/catalog-groups/{catalogGroupName}',
                    CatalogGroupOrder::class
                )->name('catalog-group-order');

                Route::get('/seasons/{season}', SeasonShow::class)
                    ->name('seasons.show');

                Route::get('/seasons/{season}/addresses/{address}', AddressShow::class)
                    ->name('addresses.show');

                Route::get(
                    '/seasons/{season}/addresses/{address}/brands/{brand}',
                    BrandShow::class
                )->name('brands.show');
            });
    });