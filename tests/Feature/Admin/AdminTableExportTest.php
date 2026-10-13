<?php

namespace Tests\Feature\Admin;

use App\Exports\AdminTableExport;
use App\Models\PricingCalculationRow;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTableExportTest extends TestCase
{
    public function test_decimal_casts_and_editable_percentages_are_exported_as_numbers(): void
    {
        $row = new PricingCalculationRow([
            'purchase_price' => '8.8000',
            'exchange_rate' => '380.123456',
            'shipping_cost_percent' => '8.2500',
            'customs_percent' => '12.0000',
            'base_price' => '4044.9025',
            'multiplier' => '4.700000',
            'manual_price' => '1990.5000',
        ]);
        $export = $this->export(PricingCalculationRow::query(), [
            TextColumn::make('purchase_price'),
            TextColumn::make('exchange_rate'),
            TextInputColumn::make('shipping_cost_percent')->getStateUsing(fn () => '8.25'),
            TextInputColumn::make('customs_percent')->getStateUsing(fn () => '12'),
            TextColumn::make('base_price'),
            TextColumn::make('multiplier'),
            TextInputColumn::make('manual_price')->getStateUsing(fn () => '1990.5'),
        ]);

        $this->assertSame([8.8, 380.123456, 8.25, 12.0, 4044.9025, 4.7, 1990.5], $export->map($row));
    }

    public function test_numeric_column_metadata_and_related_decimal_casts_are_respected(): void
    {
        $record = new ExportTestRow(['uncast_amount' => '1234.50', 'amount' => '-10.2500']);
        $record->setRelation('source', new ExportTestRow(['amount' => '8.8000']));
        $export = $this->export(ExportTestRow::query(), [
            TextColumn::make('uncast_amount')->numeric(),
            TextColumn::make('amount')->money('HUF'),
            TextColumn::make('source.amount'),
        ]);

        $this->assertSame([1234.5, -10.25, 8.8], $export->map($record));
    }

    public function test_numeric_looking_identifiers_nulls_and_zero_are_preserved(): void
    {
        $record = new ExportTestRow(['code' => '00123.45', 'amount' => null]);
        $columns = [TextColumn::make('code'), TextColumn::make('amount')];
        $export = $this->export(ExportTestRow::query(), $columns);
        $this->assertSame(['00123.45', null], $export->map($record));
        $record->code = '123.45';
        $record->amount = '0.0000';
        $this->assertSame(['123.45', 0.0], $export->map($record));
    }

    #[DataProvider('xlsxColumnFormats')]
    public function test_saved_xlsx_contains_numeric_values_and_safe_text_identifiers(array $formats): void
    {
        Schema::create('admin_export_test_rows', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 4)->nullable();
            $table->decimal('uncast_amount', 15, 4)->nullable();
            $table->string('code');
        });
        ExportTestRow::create(['amount' => '1234.5600', 'uncast_amount' => '-8.2500', 'code' => '00123.45']);
        ExportTestRow::create(['amount' => '0.0000', 'uncast_amount' => null, 'code' => '=1+1']);
        $export = $this->export(ExportTestRow::query()->orderBy('id'), [
            TextColumn::make('amount')->label('Összeg'),
            TextColumn::make('uncast_amount')->numeric()->label('Korrekció'),
            TextColumn::make('code')->label('Kód'),
        ], $formats);
        $path = tempnam(sys_get_temp_dir(), 'admin-export-test-');
        $previousBinder = Cell::getValueBinder();

        try {
            file_put_contents($path, Excel::raw($export, ExcelWriter::XLSX));
            Cell::setValueBinder(new DefaultValueBinder);
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('Összeg', $sheet->getCell('A1')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
            $this->assertSame(1234.56, $sheet->getCell('A2')->getValue());
            $this->assertSame(-8.25, $sheet->getCell('B2')->getValue());
            $this->assertSame($formats['A'] ?? 'General', $sheet->getStyle('A2')->getNumberFormat()->getFormatCode());
            $this->assertSame($formats['B'] ?? 'General', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
            $this->assertSame('00123.45', $sheet->getCell('C2')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A3')->getDataType());
            $this->assertEquals(0, $sheet->getCell('A3')->getValue());
            $this->assertNull($sheet->getCell('B3')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C3')->getDataType());
            $this->assertSame('=1+1', $sheet->getCell('C3')->getValue());
            $sheet->setCellValue('D2', '=SUM(A2:B2)');
            $this->assertEqualsWithDelta(1226.31, $sheet->getCell('D2')->getCalculatedValue(), 0.000001);
            $workbook->disconnectWorksheets();
        } finally {
            Cell::setValueBinder($previousBinder);
            unlink($path);
        }
    }

    public static function xlsxColumnFormats(): array
    {
        return ['default' => [[]], 'selected' => [['A' => '#,##0', 'B' => '#,##0.000000']]];
    }

    private function export(Builder $query, array $columns, array $formats = []): AdminTableExport
    {
        $livewire = Mockery::mock(HasTable::class);
        $livewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn (Model $record): string => (string) $record->getKey());
        $table = Table::make($livewire)->query($query)->columns($columns);

        return new AdminTableExport($query, array_values($table->getColumns()), $formats);
    }
}

class ExportTestRow extends Model
{
    protected $table = 'admin_export_test_rows';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['amount' => 'decimal:4'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }
}
