<?php

namespace App\Filament\Support\Schemas;

use App\Models\PricingProject;
use App\Services\Pricing\ErpExportPreferences;
use App\Services\Pricing\ErpPricingExportService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

class ErpPricingExportForm
{
    public static function make(PricingProject $project): array
    {
        $configuration = app(ErpPricingExportService::class)->configuration($project);
        $preferences = app(ErpExportPreferences::class);
        $saved = $preferences->load($project);
        $fields = [DatePicker::make('start_date')->label('Kezdő dátum')->default(today())->required()];
        foreach (ErpPricingExportService::SLOTS as $slot => [$currency, , $label]) {
            $options = $configuration['direct'][$slot];
            $mapping = $saved['mapping'][$slot] ?? null;
            if ($mapping !== ErpPricingExportService::FORMULA && (! is_scalar($mapping) || ! array_key_exists((string) $mapping, $options))) {
                $mapping = count($options) === 1 ? array_key_first($options) : null;
            }
            $fields[] = Select::make('mapping.'.$slot)->label($currency.' '.$label.' — forrás')
                ->options($options + [ErpPricingExportService::FORMULA => 'Képlet…'])
                ->default($mapping)
                ->afterStateUpdated(fn ($state) => $preferences->remember($project, $slot, 'mapping', $state))
                ->required($options !== [])->searchable()->live();
            $fields[] = ViewField::make('formulas.'.$slot)->label($currency.' '.$label.' — képlet')
                ->view('filament.pricing.erp-formula-builder', ['formulaLists' => $configuration['lists'], 'formulaCurrencies' => $configuration['currencies']])
                ->default($saved['formulas'][$slot] ?? [])->rules(['array'])->required()
                ->afterStateUpdated(fn ($state) => $preferences->remember($project, $slot, 'formulas', $state))
                ->visible(fn (Get $get) => $get('mapping.'.$slot) === ErpPricingExportService::FORMULA);
            $fields[] = Fieldset::make($currency.' '.$label.' — képlet kerekítése')
                ->schema([
                    TextInput::make('rounding.'.$slot.'.step')->label('Kerekítés lépése')
                        ->helperText('0,01: két tizedes; 1: egész; 10: tízes; 1000: ezres.')
                        ->default($saved['rounding'][$slot]['step'] ?? '0.01')->required()->maxLength(15)->inputMode('decimal')->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $preferences->remember($project, $slot, 'step', $state)),
                    Select::make('rounding.'.$slot.'.mode')->label('Kerekítés iránya')
                        ->options(['nearest' => 'Legközelebbi', 'up' => 'Felfelé', 'down' => 'Lefelé'])
                        ->default($saved['rounding'][$slot]['mode'] ?? 'nearest')->required()->live()
                        ->afterStateUpdated(fn ($state) => $preferences->remember($project, $slot, 'mode', $state)),
                    TextInput::make('rounding.'.$slot.'.adjustment')->label('Kerekítés utáni korrekció')
                        ->helperText('Például -10 vagy -0,05; ha nem kell, legyen 0.')
                        ->default($saved['rounding'][$slot]['adjustment'] ?? '0')->required()->maxLength(16)->inputMode('decimal')->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $preferences->remember($project, $slot, 'adjustment', $state)),
                ])->columns(3)->visible(fn (Get $get) => $get('mapping.'.$slot) === ErpPricingExportService::FORMULA);
        }

        return $fields;
    }
}
