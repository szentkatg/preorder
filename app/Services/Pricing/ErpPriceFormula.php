<?php

namespace App\Services\Pricing;

use Brick\Math\BigRational;
use Brick\Math\Exception\DivisionByZeroException;
use DomainException;

/** A bounded arithmetic grammar over typed tokens; never PHP, JS or Excel eval. */
class ErpPriceFormula
{
    public const MAX_TOKENS = 128;

    private int $position = 0;

    private array $tree;

    private array $listIds = [];

    private array $currencyIds = [];

    private function __construct(private readonly array $tokens, private readonly array $allowedLists, private readonly array $allowedCurrencies) {}

    public static function compile(array $tokens, array $allowedLists, array $allowedCurrencies): self
    {
        if ($tokens === [] || count($tokens) > self::MAX_TOKENS || ! array_is_list($tokens)) {
            throw new DomainException('A képlet 1–128 építőelemből állhat.');
        }
        foreach ($tokens as $token) {
            if (! is_array($token) || array_diff(array_keys($token), ['type', 'value']) !== []
                || ! isset($token['type']) || ! is_string($token['type'])
                || ! in_array($token['type'], ['price_list', 'exchange_rate', 'number', '+', '-', '*', '/', '(', ')'], true)) {
                throw new DomainException('Ismeretlen vagy hibás képletépítő elem.');
            }
        }
        $formula = new self($tokens, array_map('strval', $allowedLists), array_map('strval', $allowedCurrencies));
        $formula->tree = $formula->expression();
        if ($formula->position !== count($tokens)) {
            throw new DomainException('Hiányzó műveleti jel vagy hibás zárójelezés a képletben.');
        }

        return $formula;
    }

    public function priceListIds(): array
    {
        return array_keys($this->listIds);
    }

    public function currencyIds(): array
    {
        return array_keys($this->currencyIds);
    }

    public function evaluate(array $prices, array $rates): BigRational
    {
        try {
            return $this->evaluateNode($this->tree, $prices, $rates);
        } catch (DivisionByZeroException) {
            throw new DomainException('Nullával osztás a képletben.');
        }
    }

    private function expression(int $depth = 0): array
    {
        $node = $this->term($depth);
        while (in_array($this->type(), ['+', '-'], true)) {
            $operator = $this->tokens[$this->position++]['type'];
            $node = [$operator, $node, $this->term($depth)];
        }

        return $node;
    }

    private function term(int $depth): array
    {
        $node = $this->operand($depth);
        while (in_array($this->type(), ['*', '/'], true)) {
            $operator = $this->tokens[$this->position++]['type'];
            $node = [$operator, $node, $this->operand($depth)];
        }

        return $node;
    }

    private function operand(int $depth): array
    {
        if ($depth > 32) {
            throw new DomainException('A képlet legfeljebb 32 szint mély lehet.');
        }
        $token = $this->tokens[$this->position++] ?? null;
        if (! $token) {
            throw new DomainException('Befejezetlen képlet: egy érték vagy zárójeles kifejezés hiányzik.');
        }
        $type = $token['type'];
        if ($type === '(') {
            $node = $this->expression($depth + 1);
            if ($this->type() !== ')') {
                throw new DomainException('Hiányzó záró zárójel a képletben.');
            }
            $this->position++;

            return $node;
        }
        if ($type === '+' || $type === '-') {
            return ['unary'.$type, $this->operand($depth + 1)];
        }
        $value = $token['value'] ?? null;
        if ($type === 'number') {
            if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 32) {
                throw new DomainException('Hibás fix szám a képletben.');
            }
            $number = str_replace(',', '.', trim((string) $value));
            if (! preg_match('/^[+-]?(?:\d{1,12}(?:\.\d{1,8})?|\.\d{1,8})$/D', $number)) {
                throw new DomainException('A fix szám legfeljebb 12 egész és 8 tizedesjegy lehet; pont vagy vessző használható.');
            }

            return ['number', $number];
        }
        if (in_array($type, ['price_list', 'exchange_rate'], true)) {
            if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9]\d{0,9}$/D', (string) $value)) {
                throw new DomainException('Hibás árlista- vagy pénznemazonosító a képletben.');
            }
            $allowed = $type === 'price_list' ? $this->allowedLists : $this->allowedCurrencies;
            if (! in_array((string) $value, $allowed, true)) {
                throw new DomainException($type === 'price_list'
                    ? 'A képlet csak az árprojekt legutóbbi publikálásában szereplő árlistákra hivatkozhat.'
                    : 'A képlet árfolyamához aktív pénznemet kell választani.');
            }
            if ($type === 'price_list') {
                $this->listIds[(int) $value] = true;
            } else {
                $this->currencyIds[(int) $value] = true;
            }

            return [$type, (int) $value];
        }
        throw new DomainException('Érték helyett műveleti jel vagy hibás zárójel szerepel a képletben.');
    }

    private function type(): ?string
    {
        return $this->tokens[$this->position]['type'] ?? null;
    }

    private function evaluateNode(array $node, array $prices, array $rates): BigRational
    {
        [$type, $value] = $node;
        if ($type === 'number') {
            return BigRational::of($value);
        }
        if ($type === 'price_list' || $type === 'exchange_rate') {
            $values = $type === 'price_list' ? $prices : $rates;
            if (! isset($values[$value])) {
                throw new DomainException($type === 'price_list' ? 'Hiányzó publikált ár a képlet egyik árlistájában (#'.$value.').' : 'Hiányzó szezonárfolyam (#'.$value.').');
            }

            return BigRational::of($values[$value]);
        }
        $left = $this->evaluateNode($value, $prices, $rates);
        if ($type === 'unary+') {
            return $left;
        }
        if ($type === 'unary-') {
            return $left->negated();
        }
        $right = $this->evaluateNode($node[2], $prices, $rates);

        return match ($type) {
            '+' => $left->plus($right), '-' => $left->minus($right),
            '*' => $left->multipliedBy($right), '/' => $left->dividedBy($right),
        };
    }
}
