<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PartnerPriceListPdf
{
    public function render(array $document): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(public_path());
        $options->setTempDir(storage_path('framework/cache'));
        $options->setFontCache(storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('exports.partner-price-list', ['document' => $document])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 65, $canvas->get_height() - 20, '{PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.3, 0.3, 0.3]);

        return $pdf->output();
    }
}
