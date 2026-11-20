<?php

namespace App\Filament\Support;

use App\Exports\AdminImportTemplate;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

trait HasImportTemplateDownload
{
    protected function importTemplateAction(string $type): Action
    {
        return Action::make('downloadTemplate')
            ->label('Excel-minta letöltése')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function () use ($type) {
                abort_unless(static::canAccess(), 403);
                $template = new AdminImportTemplate($type);

                return Excel::download($template, $template->filename());
            });
    }
}
