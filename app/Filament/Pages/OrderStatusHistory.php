<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminResourceTable;
use App\Models\OrderStatusLog;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class OrderStatusHistory extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Rendelések';

    protected static ?string $title = 'Rendelési eseménynapló';

    protected string $view = 'filament.pages.order-status-history';

    public static function canAccess(): bool
    {
        return auth('web')->user()?->can('View:OrderStatusHistory') ?? false;
    }

    public function mount(): void
    {
        Gate::authorize('View:OrderStatusHistory');
    }

    public function hydrate(): void
    {
        Gate::authorize('View:OrderStatusHistory');
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure($table->query(OrderStatusLog::query())->columns([
            TextColumn::make('occurred_at')->label('Időpont (budapesti idő)')
                ->getStateUsing(fn (OrderStatusLog $record) => $record->occurred_at->setTimezone(OrderStatusLog::DISPLAY_TIMEZONE)->format('Y.m.d. H:i:s')),
            TextColumn::make('event')->label('Művelet')->formatStateUsing(fn ($state) => OrderStatusLog::EVENT_LABELS[$state] ?? $state),
            TextColumn::make('reference_number')->label('Hivatkozási szám'),
            TextColumn::make('partner_code')->label('Partnerkód'),
            TextColumn::make('partner_name')->label('Partner'),
            TextColumn::make('address_code')->label('Címkód'),
            TextColumn::make('season_code')->label('Szezon'),
            TextColumn::make('user_name')->label('Felhasználó'),
            TextColumn::make('user_email')->label('Felhasználó e-mail'),
            TextColumn::make('ip_address')->label('IP-cím')->placeholder('—'),
            TextColumn::make('from_status')->label('Előző státusz')->formatStateUsing(fn ($state) => OrderStatusLog::STATUS_LABELS[$state] ?? $state),
            TextColumn::make('to_status')->label('Új státusz')->formatStateUsing(fn ($state) => OrderStatusLog::STATUS_LABELS[$state] ?? $state),
            TextColumn::make('source')->label('Mód')->formatStateUsing(fn ($state) => OrderStatusLog::SOURCE_LABELS[$state] ?? $state),
            TextColumn::make('email_requested')->label('Kért e-mailt')->formatStateUsing(fn ($state) => $state ? 'Igen' : 'Nem'),
            TextColumn::make('email_status')->label('E-mail eredménye')->formatStateUsing(fn ($state) => OrderStatusLog::EMAIL_LABELS[$state] ?? $state),
            TextColumn::make('email_finished_at')->label('E-mail művelet vége (budapesti idő)')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                ->getStateUsing(fn (OrderStatusLog $record) => $record->email_finished_at?->setTimezone(OrderStatusLog::DISPLAY_TIMEZONE)->format('Y.m.d. H:i:s')),
            TextColumn::make('email_error_type')->label('E-mail hiba típusa')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('order_id')->label('Rendelés ID')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('partner_user_id')->label('Partnerfelhasználó ID')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('id')->label('Napló ID')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('event')->label('Művelet')->multiple()->options(OrderStatusLog::EVENT_LABELS),
            SelectFilter::make('source')->label('Mód')->multiple()->options(OrderStatusLog::SOURCE_LABELS),
            SelectFilter::make('email_status')->label('E-mail eredménye')->multiple()->options(OrderStatusLog::EMAIL_LABELS),
            TernaryFilter::make('email_requested')->label('Kért e-mailt'),
            SelectFilter::make('partner_user_id')->label('Felhasználó')->multiple()->searchable()
                ->options(fn () => OrderStatusLog::query()->select('partner_user_id')
                    ->selectRaw('MAX(user_name) AS user_name, MAX(user_email) AS user_email')->groupBy('partner_user_id')->orderBy('user_name')
                    ->get()->mapWithKeys(fn ($entry) => [$entry->partner_user_id => $entry->user_name.' ('.$entry->user_email.') #'.$entry->partner_user_id])->all()),
            SelectFilter::make('season_code')->label('Szezon')->multiple()->options(fn () => OrderStatusLog::query()
                ->whereNotNull('season_code')->distinct()->orderBy('season_code')->pluck('season_code', 'season_code')->all()),
            Filter::make('period')->label('Időszak')->schema([
                DatePicker::make('from')->label('Ettől a naptól')->native(false)->displayFormat('Y.m.d.'),
                DatePicker::make('until')->label('Eddig a napig')->native(false)->displayFormat('Y.m.d.'),
            ])->query(function (Builder $query, array $data): Builder {
                $dates = Validator::make($data, ['from' => ['nullable', 'date_format:Y-m-d'],
                    'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
                if (filled($dates['from'] ?? null)) {
                    $query->where('occurred_at', '>=', CarbonImmutable::parse($dates['from'], OrderStatusLog::DISPLAY_TIMEZONE)->startOfDay()->utc());
                }
                if (filled($dates['until'] ?? null)) {
                    $query->where('occurred_at', '<', CarbonImmutable::parse($dates['until'], OrderStatusLog::DISPLAY_TIMEZONE)->startOfDay()->addDay()->utc());
                }

                return $query;
            }),
        ])->defaultSort(fn (Builder $query) => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->recordActions([])->toolbarActions([])->recordAction(null)->recordUrl(null)
            ->defaultPaginationPageOption(100)->paginationPageOptions([25, 50, 100, 250]), OrderStatusLog::class, withViewAction: false);
    }
}
