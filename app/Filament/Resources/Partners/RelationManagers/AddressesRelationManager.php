<?php

namespace App\Filament\Resources\Partners\RelationManagers;

use App\Filament\Resources\PartnerAddresses\Schemas\PartnerAddressForm;
use App\Filament\Resources\PartnerAddresses\Tables\PartnerAddressesTable;
use App\Filament\Support\AdminResourceTable;
use App\Models\PartnerAddress;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public function form(Schema $schema): Schema
    {
        return PartnerAddressForm::configure($schema, showPartnerSelect: false);
    }

    public function table(Table $table): Table
    {
        $table = PartnerAddressesTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['partner_id'] = $this->getOwnerRecord()->id;

                        return $data;
                    }),
            ]);

        return AdminResourceTable::configure(
            $table,
            PartnerAddress::class,
            withViewAction: false,
        );
    }
}
