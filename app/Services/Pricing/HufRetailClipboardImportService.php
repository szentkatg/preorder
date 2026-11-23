<?php

namespace App\Services\Pricing;

use App\Models\PriceList;
use App\Models\PricingCalculationRow;
use App\Models\PricingProject;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class HufRetailClipboardImportService
{
    public const MAX_ROWS = 2000;

    public const MAX_LENGTH = 500000;

    public function __construct(private readonly RetailPriceReviewService $review) {}

    /** Only existing calculations in this project/list are changed; published prices are never written. */
    public function import(PricingProject $project, PriceList $list, string $text): array
    {
        Gate::authorize('update', new PricingCalculationRow);
        $entries = $this->parse($text);

        return DB::transaction(function () use ($project, $list, $entries): array {
            $project = PricingProject::query()->lockForUpdate()->findOrFail($project->id);
            if (! $project->isOpenForPricing()) {
                throw new DomainException('Csak nem lezárt árprojektbe tölthetők manuális árak.');
            }
            $list = PriceList::query()->whereKey($list->id)->where('active', true)->where('type', 'retail')
                ->whereHas('currency', fn ($query) => $query->where('code', 'HUF'))->first();
            if (! $list) {
                throw new DomainException('Aktív HUF kisker árlistát válassz.');
            }
            $products = $project->products()->get(['products.id', 'products.model_code'])
                ->groupBy(fn ($product) => self::normalizeModelCode($product->model_code));
            $rows = PricingCalculationRow::query()->where('pricing_project_id', $project->id)
                ->where('price_list_id', $list->id)->where('currency_id', $list->currency_id)
                ->where('price_type', PricingCalculationRow::TYPE_RETAIL)->orderBy('id')->lockForUpdate()->get()->groupBy('product_id');
            $counts = collect($entries)->whereNotNull('model_code')->countBy('model_code');
            $result = ['project' => $project->name, 'price_list' => $list->code, 'updated_rows' => 0, 'updated_models' => 0,
                'equal' => 0, 'not_found' => 0, 'unchanged' => 0, 'errors' => 0, 'entries' => [], 'row_ids' => []];

            foreach ($entries as $entry) {
                $entry += ['updated_rows' => 0, 'equal_rows' => 0, 'calculated_prices' => []];
                if ($entry['status'] === 'error') {
                    $result['errors']++;
                } elseif ($counts[$entry['model_code']] > 1) {
                    $entry['status'] = 'error';
                    $entry['message'] = 'A modellkód többször szerepel a vágólapon; egyik előfordulását sem töltöttük be.';
                    $result['errors']++;
                } elseif (($matches = $products->get($entry['model_code'], collect()))->isEmpty()) {
                    $entry['status'] = 'not_found';
                    $entry['message'] = 'Nem található modell a kiválasztott árprojektben.';
                    $result['not_found']++;
                } elseif ($matches->count() > 1) {
                    $entry['status'] = 'error';
                    $entry['message'] = 'A megtisztított kódhoz több modell tartozik; nem választunk helyetted.';
                    $result['errors']++;
                } elseif (($modelRows = $rows->get($matches->first()->id, collect()))->isEmpty()) {
                    $entry['status'] = 'not_found';
                    $entry['message'] = 'A modell megvan, de ehhez az árlistához még nincs HUF kisker kalkulációja.';
                    $result['not_found']++;
                } else {
                    $entry['calculated_prices'] = $modelRows->pluck('calculated_price')->unique()->values()->all();
                    try {
                        // A model with color-level calculations is imported atomically, including dependency invalidation.
                        $changed = DB::transaction(function () use ($modelRows, $entry): array {
                            $changed = ['ids' => [], 'equal' => 0];
                            foreach ($modelRows as $row) {
                                Gate::authorize('update', $row);
                                if ($row->calculated_price === null || BigDecimal::of($row->calculated_price)->isLessThanOrEqualTo(0)) {
                                    throw new DomainException('Hiányzó vagy nem pozitív kalkulált ár; előbb számítsd újra a HUF kisker árakat.');
                                }
                                if (BigDecimal::of($entry['price'])->isEqualTo($row->calculated_price)) {
                                    $changed['equal']++;

                                    continue;
                                }
                                if ($row->manual_price !== null && BigDecimal::of($entry['price'])->isEqualTo($row->manual_price)) {
                                    continue;
                                }
                                $this->review->setManualPrice($row, $entry['price']);
                                $changed['ids'][] = $row->id;
                            }

                            return $changed;
                        });
                        $entry['updated_rows'] = count($changed['ids']);
                        $entry['equal_rows'] = $changed['equal'];
                        $entry['status'] = $changed['ids'] !== [] ? 'updated' : ($changed['equal'] === $modelRows->count() ? 'equal' : 'unchanged');
                        $entry['message'] = match ($entry['status']) {
                            'updated' => 'Manuális ár betöltve: '.$entry['updated_rows'].' ársor.'
                                .($changed['equal'] ? ' Kalkulált árral egyező, érintetlenül hagyott ársor: '.$changed['equal'].'.' : ''),
                            'equal' => 'A kalkulált árral egyezik, nem írtunk be manuális árat. A korábbi manuális ár is változatlan.',
                            default => 'A többi ársorban már ez a manuális ár szerepel; nem változtattuk meg a jóváhagyását.'
                                .($changed['equal'] ? ' Kalkulált árral egyező, kihagyott ársor: '.$changed['equal'].'.' : ''),
                        };
                        $result[$entry['status'] === 'updated' ? 'updated_models' : $entry['status']]++;
                        $result['updated_rows'] += $entry['updated_rows'];
                        array_push($result['row_ids'], ...$changed['ids']);
                    } catch (DomainException|AuthorizationException $exception) {
                        $entry['status'] = 'error';
                        $entry['message'] = $exception instanceof AuthorizationException ? 'Nincs módosítási jogosultság ehhez a modellhez.' : $exception->getMessage();
                        $result['errors']++;
                    }
                }
                $result['entries'][] = $entry;
            }

            return $result;
        });
    }

    public static function normalizeModelCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/[\s\p{Z}\-\x{2010}-\x{2015}\x{2212}]+/u', '', $code) ?? '');
    }

    private function parse(string $text): array
    {
        if (strlen($text) > self::MAX_LENGTH) {
            throw new DomainException('Túl nagy a beillesztett szöveg (legfeljebb 500 000 bájt).');
        }
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/^\x{FEFF}/u', '', $text));
        $entries = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, "\t", '"', '');
            if ($entries === [] && count($cells) === 2 && $this->isHeader($cells)) {
                continue;
            }
            $code = self::normalizeModelCode((string) ($cells[0] ?? ''));
            $entry = ['line' => $index + 1, 'input_code' => mb_substr((string) ($cells[0] ?? ''), 0, 255),
                'model_code' => $code !== '' ? $code : null, 'input_price' => mb_substr((string) ($cells[1] ?? ''), 0, 80),
                'price' => null, 'status' => 'pending', 'message' => ''];
            try {
                if (count($cells) !== 2 || $code === '' || mb_strlen($code) > 255) {
                    throw new DomainException('Pontosan két, tabulátorral elválasztott oszlop szükséges: modellkód és ár.');
                }
                $entry['price'] = $this->parsePrice((string) $cells[1]);
            } catch (DomainException $exception) {
                $entry['status'] = 'error';
                $entry['message'] = $exception->getMessage();
            }
            $entries[] = $entry;
            if (count($entries) > self::MAX_ROWS) {
                throw new DomainException('Egyszerre legfeljebb '.self::MAX_ROWS.' adatsor illeszthető be.');
            }
        }
        if ($entries === []) {
            throw new DomainException('Illessz be legalább egy modellkód–ár sort.');
        }

        return $entries;
    }

    private function parsePrice(string $value): string
    {
        $value = preg_replace('/[\s\p{Z}]+/u', '', $value);
        $value = preg_replace('/(?:HUF|Ft)$/iu', '', $value);
        if (! preg_match('/^\d{1,11}(?:[.,]\d{1,4})?$/D', $value)) {
            throw new DomainException('Az ár pozitív szám legyen, legfeljebb négy tizedessel (pl. 12 990 vagy 12990,50).');
        }
        $number = BigDecimal::of(str_replace(',', '.', $value))->toScale(4);
        if ($number->isLessThanOrEqualTo(0)) {
            throw new DomainException('Az ár csak pozitív szám lehet.');
        }

        return (string) $number;
    }

    private function isHeader(array $cells): bool
    {
        $clean = static fn ($value) => preg_replace('/[^a-z]/', '', Str::ascii(mb_strtolower(trim($value))));

        return in_array($clean($cells[0]), ['modellkod', 'modelkod', 'modelcode', 'cikkszam'], true)
            && in_array($clean($cells[1]), ['ar', 'price', 'manualisar', 'manualiskiskerar', 'kiskerar', 'kiskerarhuf', 'manualprice'], true);
    }
}
