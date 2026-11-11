<?php

namespace App\Filament\Support;

use App\Filament\Pages\PartnerPriceListExport;

final class PricingPageLayout
{
    /** @param array<string> $scopes */
    public static function scrollsIntro(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (str_starts_with($scope, 'App\\Filament\\Pages\\Pricing')
                || str_starts_with($scope, 'App\\Filament\\Resources\\Pricing')
                || $scope === PartnerPriceListExport::class) {
                return true;
            }
        }

        return false;
    }
}
