<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SkuReplacement extends Model
{
    public const DELETED = 'TÖRÖLT';

    protected $fillable = ['season_id', 'old_sku_code', 'new_sku_code'];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function oldSku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'old_sku_code', 'sku_code');
    }

    public function newSku(): BelongsTo
    {
        return $this->belongsTo(Sku::class, 'new_sku_code', 'sku_code');
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            // Serialize graph edits in a season, including concurrent A->B / B->A saves.
            $seasons = array_filter([$this->season_id, $this->getOriginal('season_id')]);
            Season::whereKey($seasons)->orderBy('id')->lockForUpdate()->get();
            $this->validateReplacement();

            return parent::save($options);
        });
    }

    protected function validateReplacement(): void
    {
        $this->old_sku_code = trim((string) $this->old_sku_code);
        $this->new_sku_code = trim((string) $this->new_sku_code);
        if (mb_strtoupper($this->new_sku_code, 'UTF-8') === self::DELETED) {
            $this->new_sku_code = self::DELETED;
        }
        Validator::make($this->attributesToArray(), [
            'season_id' => ['required', 'integer', 'exists:seasons,id'],
            'old_sku_code' => ['required', 'string', 'max:255', Rule::unique('sku_replacements')
                ->where('season_id', $this->season_id)->ignore($this->getKey())],
            'new_sku_code' => ['required', 'string', 'max:255'],
        ])->validate();
        foreach (['old_sku_code', 'new_sku_code'] as $field) {
            if ($field === 'new_sku_code' && $this->$field === self::DELETED) {
                continue;
            }
            $sku = Sku::where('sku_code', $this->$field)
                ->whereHas('product', fn ($query) => $query->where('season_id', $this->season_id))->first();
            if (! $sku) {
                throw ValidationException::withMessages([$field => 'A cikkszám nem található a kiválasztott szezonban.']);
            }
            // Keep the stored code identical to the SKU master (also on case-insensitive MySQL).
            $this->$field = $sku->sku_code;
        }
        $map = static::where('season_id', $this->season_id)->when($this->exists,
            fn ($query) => $query->whereKeyNot($this->id))->pluck('new_sku_code', 'old_sku_code')->all();
        $map[$this->old_sku_code] = $this->new_sku_code;
        $seen = [];
        $code = $this->old_sku_code;
        while (isset($map[$code])) {
            if (isset($seen[$code])) {
                throw ValidationException::withMessages(['new_sku_code' => 'A cikkcsere önmagára mutat vagy körkörös láncot hozna létre.']);
            }
            $seen[$code] = true;
            $code = $map[$code];
        }
    }
}
