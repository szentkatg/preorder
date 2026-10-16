<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;

class PricingProject extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'season_id',
        'name',
        'status',
        'active',
        'created_by',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function scopeOpenForPricing(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_ACTIVE]);
    }

    public function isOpenForPricing(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ACTIVE], true);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $project): void {
            if ($project->getOriginal('status') === self::STATUS_CLOSED && $project->isDirty('status') && $project->isOpenForPricing()) {
                Gate::authorize('reopen', $project);
            }
        });
    }

    public function publications(): HasMany
    {
        return $this->hasMany(PricingProjectPublication::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(PricingParameter::class);
    }

    public function roundingRules(): HasMany
    {
        return $this->hasMany(PricingRoundingRule::class);
    }

    public function calculationRows(): HasMany
    {
        return $this->hasMany(PricingCalculationRow::class);
    }

    public function calculationErrors(): HasMany
    {
        return $this->hasMany(PricingCalculationError::class);
    }
}
