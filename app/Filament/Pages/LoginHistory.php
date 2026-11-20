<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminResourceTable;
use App\Models\LoginLog;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class LoginHistory extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static string|\UnitEnum|null $navigationGroup = 'Rendszerbeállítások';

    protected static ?string $title = 'Belépési napló';

    protected string $view = 'filament.pages.login-history';

    public static function canAccess(): bool
    {
        return auth('web')->user()?->can('View:LoginHistory') ?? false;
    }

    public function mount(): void
    {
        Gate::authorize('View:LoginHistory');
    }

    public function hydrate(): void
    {
        Gate::authorize('View:LoginHistory');
    }

    public function table(Table $table): Table
    {
        return AdminResourceTable::configure($table
            ->query(LoginLog::query())
            ->columns([
                TextColumn::make('logged_in_at')->label('Belépés időpontja (budapesti idő)')
                    ->getStateUsing(fn (LoginLog $record) => $record->logged_in_at->setTimezone(LoginLog::DISPLAY_TIMEZONE)->format('Y.m.d. H:i:s')),
                TextColumn::make('guard')->label('Felület')
                    ->getStateUsing(fn (LoginLog $record) => LoginLog::GUARD_LABELS[$record->guard] ?? $record->guard),
                TextColumn::make('user_name')->label('Felhasználó'),
                TextColumn::make('user_email')->label('E-mail'),
                TextColumn::make('ip_address')->label('IP-cím')->placeholder('—'),
                TextColumn::make('user_id')->label('Felhasználó ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label('Napló ID')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('guard')->label('Felület')->options(LoginLog::GUARD_LABELS)->multiple(),
                Filter::make('period')->label('Belépés időszaka')->schema([
                    DatePicker::make('from')->label('Ettől a naptól')->native(false)->displayFormat('Y.m.d.'),
                    DatePicker::make('until')->label('Eddig a napig')->native(false)->displayFormat('Y.m.d.'),
                ])->query(function (Builder $query, array $data): Builder {
                    $dates = Validator::make($data, [
                        'from' => ['nullable', 'date_format:Y-m-d'],
                        'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
                    ])->validate();

                    if (filled($dates['from'] ?? null)) {
                        $query->where('logged_in_at', '>=', CarbonImmutable::parse($dates['from'], LoginLog::DISPLAY_TIMEZONE)->startOfDay()->utc());
                    }
                    if (filled($dates['until'] ?? null)) {
                        $query->where('logged_in_at', '<', CarbonImmutable::parse($dates['until'], LoginLog::DISPLAY_TIMEZONE)->startOfDay()->addDay()->utc());
                    }

                    return $query;
                })->indicateUsing(fn (array $data): array => array_filter([
                    filled($data['from'] ?? null) ? 'Belépés ettől: '.$data['from'] : null,
                    filled($data['until'] ?? null) ? 'Belépés eddig: '.$data['until'] : null,
                ])),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('logged_in_at')->orderByDesc('id'))
            ->recordActions([])->toolbarActions([])->recordAction(null)->recordUrl(null)
            ->defaultPaginationPageOption(100)->paginationPageOptions([25, 50, 100, 250]),
            LoginLog::class, withViewAction: false);
    }
}
