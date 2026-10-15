<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class ForeignRetailPriceListResolver
{
    public function sources(): Collection
    {
        return PriceList::query()->where('type', 'retail')->where('active', true)
            ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))
            ->with('currency')->orderBy('code')->get();
    }

    public function targets(?string $currency = null): Collection
    {
        return PriceList::query()->where('type', 'retail')->where('active', true)
            ->whereHas('currency', fn ($query) => $query->whereIn('code', ForeignRetailCalculator::CURRENCIES)
                ->when($currency, fn ($query) => $query->where('code', $currency)))
            ->with('currency')->orderBy('code')->get();
    }

    public function resolvePair(string $currency, mixed $sourceId = null, mixed $targetId = null): array
    {
        return ['source' => $this->resolve($this->sources(), $sourceId, 'HUF forrásárlista'),
            'target' => $this->resolve($this->targets($currency), $targetId, $currency.' célárlista')];
    }

    /** @return array<int, array{source: PriceList, target: PriceList}> */
    public function resolveAll(array $sourceSelections = []): array
    {
        $sources = $this->sources();
        $targets = $this->targets();
        if ($targets->isEmpty()) {
            throw new DomainException('Nincs aktív EUR, CZK, RON vagy PLN kisker célárlista.');
        }
        if (array_diff(array_map('strval', array_keys($sourceSelections)), $targets->modelKeys())) {
            throw new DomainException('Az árlisták időközben megváltoztak. Nyisd meg újra az újraszámítást.');
        }
        $pairs = [];
        foreach ($targets as $target) {
            $pairs[] = ['source' => $this->resolve($sources, $sourceSelections[$target->id] ?? null, $target->code.' HUF forrásárlistája'),
                'target' => $target];
        }

        return $pairs;
    }

    private function resolve(Collection $lists, mixed $id, string $label): PriceList
    {
        if ($lists->isEmpty()) {
            throw new DomainException('Nincs aktív '.$label.'.');
        }
        if ($id === null || $id === '') {
            if ($lists->count() !== 1) {
                throw new DomainException('Több lehetséges '.$label.' van. Válaszd ki a megfelelőt.');
            }

            return $lists->first();
        }
        $list = (is_scalar($id) && ctype_digit((string) $id)) ? $lists->find((int) $id) : null;
        if (! $list) {
            throw new DomainException('A kiválasztott '.$label.' már nem választható. Nyisd meg újra az újraszámítást.');
        }

        return $list;
    }
}
