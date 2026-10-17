<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use Illuminate\Support\Facades\DB;

class PricingOverviewQuery
{
    /** Correlated, color-safe SQL projections keep every displayed price/multiplier sortable and filterable. */
    public function columns(PriceList $list): array
    {
        $table = 'pricing_calculation_rows';
        $base = DB::table($table.' as overview_price')->whereColumn('overview_price.pricing_project_id', $table.'.pricing_project_id')
            ->whereColumn('overview_price.product_id', $table.'.product_id')->whereColumn('overview_price.color_key', $table.'.color_key')
            ->where('overview_price.price_list_id', $list->id)->where('overview_price.price_type', $list->type)
            ->where('overview_price.currency_id', $list->currency_id)->limit(1);
        $rate = $list->currency->code === 'HUF' ? '1.0'
            : 'COALESCE(overview_price.retail_exchange_rate, (SELECT rate_source.retail_exchange_rate FROM '.$table.' AS rate_source WHERE rate_source.id = overview_price.source_retail_row_id))';
        $retail = '(SELECT retail_source.final_price FROM '.$table.' AS retail_source WHERE retail_source.id = overview_price.source_retail_row_id)';
        $wholesale = '(SELECT wholesale_source.final_price FROM '.$table.' AS wholesale_source WHERE wholesale_source.id = overview_price.source_wholesale_row_id)';
        $values = ['price' => ['Végleges '.$list->currency->code, 'overview_price.final_price'],
            'parameter' => [match ($list->type) {
                'retail' => $list->currency->code === 'HUF' ? 'Kisker szorzó paraméter' : 'Országszorzó paraméter',
                'wholesale' => 'Nagyker szorzó paraméter', 'distributor' => 'Kedvezmény %', 'outlet' => 'Min. kedvezmény %',
            }, match ($list->type) {
                'distributor' => 'overview_price.distributor_discount_percent', 'outlet' => 'overview_price.outlet_discount_percent', default => 'overview_price.multiplier',
            }],
            'cost_ratio' => ['Ár / bekerülési érték (HUF)', 'CASE WHEN '.$rate.' > 0 THEN overview_price.final_price * '.$rate.' / NULLIF('.$table.'.final_price, 0) ELSE NULL END'],
            'status' => ['Státusz', 'overview_price.status'], 'review' => ['Felülvizsgálandó', 'overview_price.requires_review']];
        if ($list->currency->code !== 'HUF') {
            $values['rate'] = ['Használt árfolyam (HUF/'.$list->currency->code.')', $rate];
        }
        if ($list->type !== 'retail') {
            $values['retail_ratio'] = ['Bruttó kisker / ár', $retail.' / NULLIF(overview_price.final_price, 0)'];
        }
        if ($list->type === 'distributor') {
            $values['wholesale_ratio'] = ['Nettó nagyker / ár', $wholesale.' / NULLIF(overview_price.final_price, 0)'];
        }
        $result = [];
        foreach ($values as $key => [$label, $sql]) {
            $result['overview_'.$list->id.'_'.$key] = ['label' => $list->code.' – '.$label, 'kind' => $key,
                'query' => (clone $base)->selectRaw($sql)];
        }

        return $result;
    }
}
