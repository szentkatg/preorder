<?php

namespace App\Services\Pricing;

use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use App\Models\Product;
use App\Models\Season;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PreviousSeasonRetailPriceService
{
    public function previousSeason(?Season $season): ?Season
    {
        if (! preg_match('/^([WS])(\d{2})$/i', trim($season?->code ?? ''), $matches) || (int) $matches[2] === 0) {
            return null;
        }
        $code = strtoupper($matches[1]).str_pad((string) ((int) $matches[2] - 1), 2, '0', STR_PAD_LEFT);

        return Season::query()->whereRaw('UPPER(code) = ?', [$code])->first();
    }

    public function setPair(PricingProject $project, Product $product, string $mode, ?int $previousProductId): void
    {
        Gate::authorize('update', $product);
        Gate::authorize('update', new PricingCalculationRow);
        DB::transaction(function () use ($project, $product, $mode, $previousProductId): void {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            Gate::authorize('update', $product);
            if (! $project->isOpenForPricing() || $product->season_id != $project->season_id
                || ! in_array($mode, ['auto', 'manual', 'none'], true)) {
                throw new DomainException('Csak az aktuális, nem lezárt árprojekt terméke párosítható.');
            }
            $previous = $this->previousSeason($project->season);
            if ($mode === 'manual' && (! $previous || ! Product::query()->where('season_id', $previous->id)->whereKey($previousProductId)->exists())) {
                throw new DomainException('Az előző azonos típusú szezonból válassz terméket.');
            }
            $product->previous_season_match_override = $mode !== 'auto';
            $product->previous_season_product_id = $mode === 'manual' ? $previousProductId : null;
            $product->save();
        });
    }

    public function pairQuery(?int $seasonId): QueryBuilder
    {
        $season = $seasonId ? Season::find($seasonId) : null;
        $previous = $this->previousSeason($season);
        $current = DB::table('products as current_product')->whereColumn('current_product.id', 'pricing_calculation_rows.product_id');
        if (! $previous) {
            return $current->selectRaw('NULL');
        }
        $automatic = $this->automaticCandidates($season, $previous)->selectRaw('MIN(prior_product.id)')->havingRaw('COUNT(*) = 1');
        $manual = DB::table('products as manual_product')->select('manual_product.id')
            ->whereColumn('manual_product.id', 'current_product.previous_season_product_id')
            ->where('manual_product.season_id', $previous->id);

        return $current->selectRaw('CASE WHEN current_product.previous_season_match_override = 1 THEN ('.$manual->toSql().') ELSE ('.$automatic->toSql().') END',
            [...$manual->getBindings(), ...$automatic->getBindings()]);
    }

    private function automaticCandidates(Season $season, Season $previous): QueryBuilder
    {
        // MySQL LENGTH counts UTF-8 bytes, not characters; SQLite LENGTH counts characters.
        $length = DB::connection()->getDriverName() === 'sqlite' ? 'LENGTH' : 'CHAR_LENGTH';
        $normalize = static fn (string $alias): string => "CASE WHEN UPPER(SUBSTR(TRIM({$alias}.name_hu), -3)) = ? THEN TRIM(SUBSTR(TRIM({$alias}.name_hu), 1, {$length}(TRIM({$alias}.name_hu)) - 3)) ELSE TRIM({$alias}.name_hu) END";

        return DB::table('products as prior_product')->where('prior_product.season_id', $previous->id)
            ->whereRaw('(prior_product.brand_id = current_product.brand_id OR (prior_product.brand_id IS NULL AND current_product.brand_id IS NULL))')
            ->whereRaw('UPPER('.$normalize('prior_product').') = UPPER('.$normalize('current_product').')', [strtoupper($previous->code), strtoupper($season->code)])
            ->whereRaw('('.$normalize('current_product').") <> ''", [strtoupper($season->code)]);
    }

    public function matchCountQuery(?int $seasonId): QueryBuilder
    {
        $season = $seasonId ? Season::find($seasonId) : null;
        $previous = $this->previousSeason($season);
        $query = DB::table('products as current_product')->whereColumn('current_product.id', 'pricing_calculation_rows.product_id');

        return $previous ? $query->selectSub($this->automaticCandidates($season, $previous)->selectRaw('COUNT(*)'), 'candidate_count') : $query->selectRaw('0');
    }

    public function priceQuery(?int $seasonId): QueryBuilder
    {
        $previous = $this->previousSeason($seasonId ? Season::find($seasonId) : null);

        return DB::table('price_list_items as previous_price')->select('previous_price.net_price')
            ->where('previous_price.season_id', $previous?->id ?? 0)
            ->whereColumn('previous_price.price_list_id', 'pricing_calculation_rows.price_list_id')
            ->where('previous_price.product_id', $this->pairQuery($seasonId))->limit(1);
    }

    public function addColumns(Builder $query, ?int $seasonId): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('pricing_calculation_rows.*');
        }

        return $query->selectSub($this->priceQuery($seasonId), 'previous_season_price')
            ->selectSub($this->pairQuery($seasonId), 'matched_previous_product_id')
            ->selectSub($this->matchCountQuery($seasonId), 'previous_season_match_count');
    }
}
