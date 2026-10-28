<?php

namespace App\Services\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ErpFormulaRounding
{
    private function __construct(private readonly BigDecimal $step, private readonly RoundingMode $mode, private readonly BigDecimal $adjustment) {}

    public static function from(mixed $settings): self
    {
        if (! is_array($settings)) {
            throw ValidationException::withMessages(['step' => 'Hibás kerekítési beállítás.']);
        }
        $mode = $settings['mode'] ?? 'nearest';
        if (! is_string($mode) || ! in_array($mode, ['nearest', 'up', 'down'], true)) {
            throw ValidationException::withMessages(['mode' => 'Válassz kerekítési irányt.']);
        }
        $numbers = [];
        foreach (['step' => '0.01', 'adjustment' => '0'] as $field => $default) {
            $value = $settings[$field] ?? $default;
            $number = is_string($value) || is_int($value) || is_float($value) ? str_replace(',', '.', trim((string) $value)) : '';
            if (! preg_match('/^[+-]?\d{1,11}(?:\.\d{1,2})?$/D', $number)) {
                throw ValidationException::withMessages([$field => 'Legfeljebb 11 egész és 2 tizedesjegyű számot adj meg, ponttal vagy vesszővel.']);
            }
            $numbers[$field] = BigDecimal::of($number);
        }
        if ($numbers['step']->isLessThan('0.01')) {
            throw ValidationException::withMessages(['step' => 'A kerekítés lépése legalább 0,01 legyen.']);
        }

        return new self($numbers['step'], match ($mode) {
            'up' => RoundingMode::CEILING, 'down' => RoundingMode::FLOOR, default => RoundingMode::HALF_UP,
        }, $numbers['adjustment']);
    }

    public function apply(BigRational $value): BigDecimal
    {
        return $value->dividedBy($this->step)->toScale(0, $this->mode)
            ->multipliedBy($this->step)->plus($this->adjustment)->toScale(2, RoundingMode::HALF_UP);
    }
}
