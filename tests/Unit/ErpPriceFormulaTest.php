<?php

namespace Tests\Unit;

use App\Services\Pricing\ErpPriceFormula;
use Brick\Math\RoundingMode;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ErpPriceFormulaTest extends TestCase
{
    private static function number(string $value): array
    {
        return ['type' => 'number', 'value' => $value];
    }

    private static function tokens(array $items): array
    {
        return array_map(fn ($item) => in_array($item, ['+', '-', '*', '/', '(', ')'], true) ? ['type' => $item] : self::number($item), $items);
    }

    #[DataProvider('arithmetic')]
    public function test_arithmetic_precedence_parentheses_and_decimal_precision(array $items, string $expected): void
    {
        $result = ErpPriceFormula::compile(self::tokens($items), [], [])->evaluate([], []);
        $this->assertSame($expected, (string) $result->toScale(8, RoundingMode::HALF_UP));
    }

    public static function arithmetic(): array
    {
        return [
            [['2', '+', '3', '*', '4'], '14.00000000'],
            [['(', '2', '+', '3', ')', '*', '4'], '20.00000000'],
            [['18', '/', '3', '/', '2'], '3.00000000'],
            [['1', '-', '2', '-', '3'], '-4.00000000'],
            [['0,1', '+', '0.2'], '0.30000000'],
            [['1', '/', '3', '*', '3'], '1.00000000'],
            [['-', '(', '-', '2', ')', '+', '.5'], '2.50000000'],
            [['+', '1.27', '*', '-2'], '-2.54000000'],
            [['(', '1', '+', '(', '2', '-', '0.5', ')', ')', '/', '2'], '1.25000000'],
        ];
    }

    public function test_typed_price_and_currency_references_are_resolved_from_given_values(): void
    {
        $formula = ErpPriceFormula::compile([
            ['type' => 'price_list', 'value' => '10'], ['type' => '*'],
            ['type' => 'exchange_rate', 'value' => 20], ['type' => '/'],
            ['type' => 'exchange_rate', 'value' => '21'],
        ], [10], [20, 21]);
        $this->assertSame([10], $formula->priceListIds());
        $this->assertSame([20, 21], $formula->currencyIds());
        $this->assertSame('250.0000', (string) $formula->evaluate([10 => '10'], [20 => '400', 21 => '16'])->toScale(4));
    }

    #[DataProvider('invalid')]
    public function test_invalid_or_untrusted_formulas_are_rejected(array $tokens): void
    {
        $this->expectException(DomainException::class);
        ErpPriceFormula::compile($tokens, [10], [20]);
    }

    public static function invalid(): array
    {
        return [
            [[]], [[['type' => 'number', 'value' => '1e3']]], [[self::number('INF')]],
            [[self::number('1;phpinfo()')]], [[self::number('=SUM(A1:A2)')]],
            [[self::number('9999999999999')]], [[self::number('0.123456789')]],
            [[self::number('1 000')]], [[['type' => 'number', 'value' => ['1']]]],
            [[['type' => 'function', 'value' => 'system']]], [[['type' => 'number', 'value' => '1', 'code' => 'eval']]],
            [[['type' => 'price_list', 'value' => 999]]], [[['type' => 'exchange_rate', 'value' => 999]]],
            [[['type' => 'price_list', 'value' => '10 OR 1=1']]],
            [self::tokens(['2', '3'])], [self::tokens(['2', '+'])], [self::tokens(['*', '2'])],
            [self::tokens(['(', ')'])], [self::tokens(['(', '2'])], [self::tokens(['2', ')'])],
            [array_fill(0, 129, self::number('1'))],
            [[1 => self::number('1')]],
            [self::tokens([...array_fill(0, 33, '('), '1', ...array_fill(0, 33, ')')])],
        ];
    }

    public function test_division_by_zero_is_reported_without_evaluating_code(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Nullával osztás');
        ErpPriceFormula::compile(self::tokens(['5', '/', '(', '2', '-', '2', ')']), [], [])->evaluate([], []);
    }

    public function test_missing_product_price_is_not_silently_treated_as_zero(): void
    {
        $this->expectException(DomainException::class);
        ErpPriceFormula::compile([['type' => 'price_list', 'value' => 10]], [10], [])->evaluate([], []);
    }
}
