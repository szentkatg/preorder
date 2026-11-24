<?php

namespace App\Services\Pricing;

use App\Models\Season;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class RetailSeasonPurchasePriceComparison
{
    public const FIELDS = [
        'current_season_purchase_price' => 'comparison_current.purchase_price',
        'current_season_purchase_currency' => 'comparison_currency.code',
        'previous_season_purchase_price' => 'comparison_prior.purchase_price',
        'previous_season_purchase_currency' => 'comparison_currency.code',
    ];

    public function fieldQuery(string $field, ?int $seasonId): QueryBuilder
    {
        if (! isset(self::FIELDS[$field])) {
            throw new DomainException('Ismeretlen beszerzésiár-összehasonlító oszlop.');
        }

        return (str_starts_with($field, 'current_') ? $this->currentOffer() : $this->previousOffer($seasonId))
            ->select(self::FIELDS[$field]);
    }

    /** @param array<string>|null $fields */
    public function addColumns(Builder $query, ?int $seasonId, ?array $fields = null): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('pricing_calculation_rows.*');
        }
        $current = $this->currentOffer();
        $previous = $this->previousOffer($seasonId);
        foreach ($fields ?? array_keys(self::FIELDS) as $field) {
            if (! isset(self::FIELDS[$field])) {
                throw new DomainException('Ismeretlen beszerzésiár-összehasonlító oszlop.');
            }
            $offer = str_starts_with($field, 'current_') ? $current : $previous;
            $query->selectSub((clone $offer)->select(self::FIELDS[$field]), $field);
        }

        return $query;
    }

    private function currentOffer(): QueryBuilder
    {
        return DB::table('product_purchase_prices as comparison_current')
            ->join('currencies as comparison_currency', 'comparison_currency.id', '=', 'comparison_current.currency_id')
            ->whereColumn('comparison_current.product_purchase_price_id', 'pricing_calculation_rows.product_purchase_price_id')
            ->whereColumn('comparison_current.product_id', 'pricing_calculation_rows.product_id')
            ->whereColumn('comparison_current.color_key', 'pricing_calculation_rows.color_key')
            ->where('comparison_current.active', true)->where('comparison_current.purchase_price', '>=', 0)->limit(1);
    }

    private function previousOffer(?int $seasonId): QueryBuilder
    {
        $pairing = app(PreviousSeasonRetailPriceService::class);
        $previous = $pairing->previousSeason($seasonId ? Season::find($seasonId) : null);
        $offers = DB::table('product_purchase_prices as comparison_prior')
            ->join('currencies as comparison_currency', 'comparison_currency.id', '=', 'comparison_prior.currency_id')
            ->leftJoin('exchange_rates as comparison_rate', fn ($join) => $join
                ->on('comparison_rate.currency_id', '=', 'comparison_prior.currency_id')
                ->where('comparison_rate.season_id', '=', $previous?->id ?? 0)->where('comparison_rate.active', '=', true))
            ->where('comparison_prior.active', true)->where('comparison_prior.purchase_price', '>=', 0)
            ->where('comparison_prior.product_id', $pairing->pairQuery($seasonId))
            ->where(function ($query): void {
                // Model offers apply to all colors; a color offer must match
                // the same color code on the paired product, never another color.
                $query->whereNull('comparison_prior.color_id')->orWhereExists(
                    DB::table('colors as comparison_prior_color')
                        ->join('colors as comparison_current_color', 'comparison_current_color.code', '=', 'comparison_prior_color.code')
                        ->whereColumn('comparison_prior_color.id', 'comparison_prior.color_id')
                        ->whereColumn('comparison_prior_color.product_id', 'comparison_prior.product_id')
                        ->whereColumn('comparison_current_color.id', 'pricing_calculation_rows.color_id')
                        ->whereColumn('comparison_current_color.product_id', 'pricing_calculation_rows.product_id')->selectRaw('1')
                );
            });
        if (! $previous) {
            return $offers->whereRaw('1 = 0')->limit(1);
        }
        $rate = "CASE WHEN UPPER(comparison_currency.code) = 'HUF' THEN 1.0 WHEN comparison_rate.rate_to_huf > 0 THEN comparison_rate.rate_to_huf ELSE NULL END";
        $missingRates = (clone $offers)->whereRaw('('.$rate.') IS NULL')->selectRaw('1');
        $currencies = (clone $offers)->selectRaw('COUNT(DISTINCT comparison_prior.currency_id)');

        return $offers->where(fn ($query) => $query->whereNotExists($missingRates)
            ->orWhereRaw('('.$currencies->toSql().') <= 1', $currencies->getBindings()))
            // Same-currency offers can be ranked without FX. Mixed currencies
            // require every relevant historical rate, rather than guessing.
            ->orderByRaw('comparison_prior.purchase_price * COALESCE(('.$rate.'), 1.0)')
            ->orderBy('comparison_prior.product_purchase_price_id')->limit(1);
    }
}
