<?php

namespace App\Services\Pricing;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\PricingProject;
use App\Models\PricingProjectPublication;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ErpPricingExportService
{
    public const FORMULA = '__formula__';

    // Fixed 30-column import contract; outlets have one column in the supplied template.
    public const SLOTS = [
        'huf_distributor' => ['HUF', 'distributor', 'Disztribútor ár', [6, 7]],
        'huf_wholesale' => ['HUF', 'wholesale', 'Nagyker ár', [8, 9]],
        'huf_retail' => ['HUF', 'retail', 'Kisker ár', [10, 11]],
        'huf_outlet' => ['HUF', 'outlet', 'Outlet ár', [12]],
        'eur_distributor' => ['EUR', 'distributor', 'Disztribútor ár', [13, 14]],
        'eur_wholesale' => ['EUR', 'wholesale', 'Nagyker ár', [15, 16]],
        'eur_retail' => ['EUR', 'retail', 'Kisker ár', [17, 18]],
        'czk_distributor' => ['CZK', 'distributor', 'Disztribútor ár', [19, 20]],
        'czk_retail' => ['CZK', 'retail', 'Kisker ár', [21, 22]],
        'czk_outlet' => ['CZK', 'outlet', 'Outlet ár', [23]],
        'ron_retail' => ['RON', 'retail', 'Kisker ár', [24, 25]],
        'ron_wholesale' => ['RON', 'wholesale', 'Nagyker ár', [26, 27]],
        'pln_retail' => ['PLN', 'retail', 'Kisker ár', [28, 29]],
    ];

    public function publication(PricingProject $project): PricingProjectPublication
    {
        Gate::authorize('exportErp', $project);
        Gate::authorize('view', $project);

        return $project->publications()->latest('id')->first() ?? throw new DomainException('Előbb zárd le és publikáld az árprojektet. Az ERP export a legutóbbi publikálás mentett áraiból készül.');
    }

    public function options(PricingProject $project, string $slot): array
    {
        [$currency, $type] = self::SLOTS[$slot];

        return collect($this->publication($project)->snapshot['lists'])->filter(fn ($list) => $list['currency'] === $currency && $list['type'] === $type)
            ->mapWithKeys(fn ($list) => [$list['id'] => $list['code']])->all();
    }

    public function configuration(PricingProject $project): array
    {
        $snapshot = $this->publication($project)->snapshot;
        $lists = collect($snapshot['lists']);
        $direct = [];
        foreach (self::SLOTS as $slot => [$currency, $type]) {
            $direct[$slot] = $lists->filter(fn ($list) => $list['currency'] === $currency && $list['type'] === $type)
                ->mapWithKeys(fn ($list) => [$list['id'] => $list['code']])->all();
        }
        $rates = ExchangeRate::where('season_id', $snapshot['season_id'] ?? $project->season_id)->where('active', true)
            ->pluck('rate_to_huf', 'currency_id');
        $currencies = Currency::where('active', true)->orderBy('code')->get()->mapWithKeys(function ($currency) use ($rates) {
            $rate = $currency->code === 'HUF' ? '1' : $rates->get($currency->id);
            $label = $rate !== null && (float) $rate > 0
                ? '1 '.$currency->code.' = '.rtrim(rtrim(number_format((float) $rate, 4, ',', ' '), '0'), ',').' HUF'
                : $currency->code.' — hiányzó aktív szezonárfolyam';

            return [$currency->id => $label];
        })->all();

        return ['direct' => $direct, 'lists' => $lists->mapWithKeys(fn ($list) => [$list['id'] => $list['code'].' ('.$list['currency'].')'])->all(),
            'currencies' => $currencies];
    }

    public function rows(PricingProject $project, array $mapping, string $startDate, array $formulas = []): array
    {
        $snapshot = $this->publication($project)->snapshot;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $startDate)) {
            throw ValidationException::withMessages(['start_date' => 'Érvényes kezdő dátum szükséges.']);
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $startDate);
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->format('Y-m-d') !== $startDate) {
            throw ValidationException::withMessages(['start_date' => 'Hibás kezdő dátum.']);
        }
        $headers = [array_fill(0, 30, null), array_fill(0, 30, null), array_fill(0, 30, null)];
        $headers[0][0] = 'Kezdő dátum';
        $headers[1][0] = $date->format('Y.m.d');
        foreach (['Modell kód', 'Név', 'Szezon', 'Megnevezés'] as $i => $label) {
            $headers[2][$i] = $label;
        }
        $headers[0][5] = 'HUF';
        $headers[1][5] = 'Elokalkulált beszerzési ár';
        $headers[2][5] = 'Lista ár';
        $selected = [];
        $publishedLists = collect($snapshot['lists'])->keyBy('id');
        $currencies = in_array(self::FORMULA, $mapping, true) ? Currency::where('active', true)->get()->keyBy('id') : collect();
        foreach (self::SLOTS as $slot => [$currency, $type, $label, $columns]) {
            $options = collect($snapshot['lists'])->filter(fn ($list) => $list['currency'] === $currency && $list['type'] === $type)->keyBy('id');
            $id = $mapping[$slot] ?? ($options->count() === 1 ? $options->keys()->first() : null);
            if ($id === self::FORMULA) {
                try {
                    if (! is_array($formulas[$slot] ?? null)) {
                        throw new DomainException('Építsd fel a képletet az építőelemekből.');
                    }
                    $selected[$slot] = ErpPriceFormula::compile($formulas[$slot], $publishedLists->keys()->all(), $currencies->keys()->all());
                } catch (DomainException $exception) {
                    throw ValidationException::withMessages(['formulas.'.$slot => $currency.' '.$label.': '.$exception->getMessage()]);
                }
            } elseif ($options->isNotEmpty() && (! is_scalar($id) || ! $options->has((string) $id))) {
                throw ValidationException::withMessages(['mapping.'.$slot => 'Válaszd ki a publikált '.$currency.' '.$label.' árlistát.']);
            } elseif ($options->isEmpty() && filled($id)) {
                throw ValidationException::withMessages(['mapping.'.$slot => 'Ebben a publikálásban nincs ilyen típusú/pénznemű árlista.']);
            }
            $selected[$slot] ??= $id;
            foreach ($columns as $i => $column) {
                $headers[0][$column] = $currency;
                $headers[1][$column] = $label;
                $headers[2][$column] = count($columns) === 2 && $i === 0 ? 'Eredeti' : 'Lista ár';
            }
        }
        // Read FX once per export; every product uses the same rate values.
        $rateIds = collect($selected)->filter(fn ($value) => $value instanceof ErpPriceFormula)
            ->flatMap(fn (ErpPriceFormula $formula) => $formula->currencyIds())->unique()->values()->all();
        $rates = $rateIds === [] ? [] : ExchangeRate::where('season_id', $snapshot['season_id'] ?? $project->season_id)
            ->where('active', true)->whereIn('currency_id', $rateIds)->pluck('rate_to_huf', 'currency_id')->all();
        foreach ($currencies as $currency) {
            if ($currency->code === 'HUF') {
                $rates[$currency->id] = '1';
            }
        }
        foreach ($selected as $slot => $formula) {
            if (! $formula instanceof ErpPriceFormula) {
                continue;
            }
            foreach ($formula->currencyIds() as $id) {
                if (! isset($rates[$id]) || BigDecimal::of($rates[$id])->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['formulas.'.$slot => 'Hiányzó vagy nem pozitív aktív szezonárfolyam: '.$currencies->get($id)?->code.'.']);
                }
            }
        }
        $prices = collect($snapshot['prices'])->groupBy('product_id');
        $rows = $headers;
        $codes = [];
        foreach (collect($snapshot['products'])->sortBy('model_code') as $product) {
            $code = trim($product['model_code']);
            $season = $snapshot['season_code'] ?? '';
            if ($season !== '' && str_ends_with(mb_strtoupper($code), mb_strtoupper($season))) {
                $code = mb_substr($code, 0, mb_strlen($code) - mb_strlen($season));
            }
            if ($code === '' || isset($codes[mb_strtoupper($code)])) {
                throw new DomainException('Üres vagy ismétlődő szezon nélküli modellkód: '.$code);
            }
            $codes[mb_strtoupper($code)] = true;
            $row = array_fill(0, 30, null);
            $row[0] = $code;
            $row[1] = $product['name_hu'] ?: $product['name_en'];
            $row[5] = BigDecimal::of($product['cost'])->toScale(2, RoundingMode::HALF_UP)->toFloat();
            $productPrices = $prices->get($product['id'], collect())->keyBy('price_list_id');
            foreach (self::SLOTS as $slot => [, , , $columns]) {
                $selection = $selected[$slot];
                if ($selection instanceof ErpPriceFormula) {
                    try {
                        foreach ($selection->priceListIds() as $listId) {
                            if (! $productPrices->has($listId)) {
                                throw new DomainException('Hiányzó publikált ár: '.$publishedLists->get($listId)['code'].'.');
                            }
                        }
                        $value = $selection->evaluate($productPrices->pluck('price', 'price_list_id')->all(), $rates)
                            ->toScale(2, RoundingMode::HALF_UP);
                        if ($value->isLessThanOrEqualTo(0) || $value->isGreaterThan('99999999999.99')) {
                            throw new DomainException('A képlet eredménye két tizedesre kerekítve csak pozitív, legfeljebb 99 999 999 999,99 ár lehet.');
                        }
                        $amount = $value->toFloat();
                    } catch (DomainException $exception) {
                        throw ValidationException::withMessages(['formulas.'.$slot => $product['model_code'].': '.$exception->getMessage()]);
                    }
                } else {
                    $price = $selection ? $productPrices->get($selection) : null;
                    $amount = $price ? (float) $price['price'] : null;
                }
                foreach ($columns as $column) {
                    $row[$column] = $amount;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
