<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PartnerHelpController extends Controller
{
    public function show(string $locale): View
    {
        $this->validateLocale($locale);
        $chapters = json_decode(file_get_contents(resource_path('help/content/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($chapters as &$chapter) {
            $images = $chapter['images'] ?? (isset($chapter['image']) ? [[$chapter['image'], $chapter['caption'] ?? '']] : []);
            $chapter['illustrations'] = array_map(static function (array $image) use ($locale): array {
                $path = 'help/partner-guide/'.$locale.'/'.pathinfo($image[0], PATHINFO_FILENAME).'.jpg';
                [$width, $height] = getimagesize(public_path($path));

                return ['url' => asset($path), 'caption' => $image[1], 'width' => $width, 'height' => $height];
            }, $images);
        }
        unset($chapter);

        $labels = Lang::get('partner_help', [], $locale);

        return view('partner.help', compact('locale', 'chapters', 'labels'));
    }

    public function download(string $locale): BinaryFileResponse
    {
        $this->validateLocale($locale);

        return response()->download(resource_path('help/partner-guide-'.$locale.'.pdf'), 'partner-guide-'.$locale.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function validateLocale(string $locale): void
    {
        abort_unless(in_array($locale, ['hu', 'en'], true), 404);
    }
}
