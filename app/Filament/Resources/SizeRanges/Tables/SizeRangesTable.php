<?php

namespace App\Filament\Resources\SizeRanges\Tables;

use App\Services\RecordCopyService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SizeRangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kód')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('matrix_group')
                    ->label('Mátrix csoport')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_hu')
                    ->label('Név HU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_en')
                    ->label('Név EN')
                    ->searchable(),

                TextColumn::make('sort_order')
                    ->label('Sorrend')
                    ->sortable(),

                IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean(),
            ])
            ->defaultPaginationPageOption(100)
            ->paginationPageOptions([50, 100, 250, 500, 'all'])
            ->recordActions([
                Action::make('copy')
                    ->label('Másolás')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->modalHeading('Méretlista másolása')
                    ->modalDescription('A másolás létrehoz egy új méretlistát, és átmásolja hozzá a kiválasztott méreteket is.')
                    ->form([
                        TextInput::make('code')
                            ->label('Új kód')
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'size_ranges', column: 'code'),

                        TextInput::make('name_hu')
                            ->label('Új név HU')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('name_en')
                            ->label('Új név EN')
                            ->maxLength(255),

                        TextInput::make('matrix_group')
                            ->label('Mátrix csoport')
                            ->maxLength(255),

                        TextInput::make('sort_order')
                            ->label('Sorrend')
                            ->numeric()
                            ->default(0),

                        Toggle::make('active')
                            ->label('Aktív')
                            ->default(true),
                    ])
                    ->fillForm(fn ($record): array => [
                        'code' => $record->code . '_COPY',
                        'name_hu' => $record->name_hu . ' másolat',
                        'name_en' => $record->name_en ? $record->name_en . ' copy' : null,
                        'matrix_group' => $record->matrix_group,
                        'sort_order' => $record->sort_order,
                        'active' => $record->active,
                    ])
                    ->action(function ($record, array $data): void {
                        app(RecordCopyService::class)->copy(
                            source: $record,
                            attributes: [
                                'code' => $data['code'],
                                'name_hu' => $data['name_hu'],
                                'name_en' => $data['name_en'] ?? null,
                                'matrix_group' => $data['matrix_group'] ?? null,
                                'sort_order' => $data['sort_order'] ?? 0,
                                'active' => $data['active'] ?? true,
                            ],
                            relationsToCopy: [
                                'items' => [
                                    'foreign_key' => 'size_range_id',
                                ],
                            ],
                        );

                        Notification::make()
                            ->title('Méretlista másolva')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}