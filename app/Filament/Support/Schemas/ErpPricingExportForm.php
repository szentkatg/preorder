<?php

namespace App\Filament\Support\Schemas;

use App\Models\PricingProject;
use App\Services\Pricing\ErpPricingExportService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;

class ErpPricingExportForm
{
    public static function make(PricingProject $project): array
    {
        $configuration = app(ErpPricingExportService::class)->configuration($project);
        $fields = [DatePicker::make('start_date')->label('Kezdő dátum')->default(today())->required()];
        foreach (ErpPricingExportService::SLOTS as $slot => [$currency, , $label]) {
            $options = $configuration['direct'][$slot];
            $fields[] = Select::make('mapping.'.$slot)->label($currency.' '.$label.' — forrás')
                ->options($options + [ErpPricingExportService::FORMULA => 'Képlet…'])
                ->default(count($options) === 1 ? array_key_first($options) : null)
                ->required($options !== [])->searchable()->live();
            $fields[] = ViewField::make('formulas.'.$slot)->label($currency.' '.$label.' — képlet')
                ->view('filament.pricing.erp-formula-builder', ['formulaLists' => $configuration['lists'], 'formulaCurrencies' => $configuration['currencies']])
                ->default([])->rules(['array'])->required()
                ->visible(fn (Get $get) => $get('mapping.'.$slot) === ErpPricingExportService::FORMULA);
        }

        return $fields;
    }
}
