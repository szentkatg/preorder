<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only projection of the HUF retail calculation rows, not a separate database table. */
class HufRetailPriceDistributionRow extends Model
{
    protected $table = 'pricing_calculation_rows';

    protected function casts(): array
    {
        return ['final_price' => 'decimal:4', 'model_count' => 'integer', 'color_count' => 'integer'];
    }

    public function pricingProject(): BelongsTo
    {
        return $this->belongsTo(PricingProject::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function itemMainGroup(): BelongsTo
    {
        return $this->belongsTo(ItemMainGroup::class);
    }
}
