<!doctype html>
<html lang="{{ $document['locale'] }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26pt 24pt 30pt; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; line-height: 1.15; color: #202735; }
        h1 { font-size: 15pt; margin: 0 0 5pt; }
        h2 { font-size: 11pt; margin: 0 0 10pt; }
        p { margin: 3pt 0 10pt; }
        .section { page-break-before: always; }
        .section:first-child { page-break-before: auto; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th { background: #27364b; color: white; padding: 4pt 3pt; text-align: center; font-size: 8pt; }
        td { border-bottom: .4pt solid #d8dde5; padding: 2.5pt 3pt; vertical-align: middle; overflow-wrap: break-word; }
        .group td { background: #e8edf3; font-weight: bold; }
        .group { page-break-after: avoid; }
        .number { text-align: right; white-space: nowrap; }
        .note { font-size: 8pt; font-style: italic; }
        .contact-block { page-break-inside: avoid; margin-top: 10pt; font-size: 8pt; }
        .contact-block p { margin: 2pt 0; }
    </style>
</head>
<body>
@foreach ($document['sections'] as $section)
    <div class="section">
        <h1>{{ $document['captions']['title'] }} - {{ $document['season'] }}</h1>
        <h2>{{ $section['name'] }}</h2>
        <table>
            <thead><tr>
                <th style="width:20%">{{ $document['captions']['code'] }}</th>
                <th style="width:22%">{{ $document['captions']['name'] }}</th>
                <th>{{ $document['net_label'] }} ({{ $document['currency'] }})</th>
                @if ($document['show_retail'])<th>{{ $document['retail_label'] }} ({{ $document['retail_currency'] }})</th>@endif
                <th style="width:16%">@foreach (explode(' ', $document['captions']['delivery'], 2) as $part){{ $part }}@if (!$loop->last)<br>@endif @endforeach</th>
                <th style="width:10%">@foreach (explode(' ', $document['captions']['page'], 2) as $part){{ $part }}@if (!$loop->last)<br>@endif @endforeach</th>
            </tr></thead>
            <tbody>
            @php($lastGroup = null)
            @foreach ($section['rows'] as $product)
                @if ($lastGroup !== $product['catalog_group'] && $product['catalog_group'] !== '')
                    <tr class="group"><td colspan="{{ $document['show_retail'] ? 6 : 5 }}">{{ $product['catalog_group'] }}</td></tr>
                @endif
                @php($lastGroup = $product['catalog_group'])
                <tr>
                    <td>{{ $product['code'] }}</td><td>{{ $product['name'] }}</td>
                    <td class="number">{{ $product['net_price'] === null ? '-' : number_format($product['net_price'], 2, ',', ' ') }}</td>
                    @if ($document['show_retail'])<td class="number">{{ $product['retail_price'] === null ? '-' : number_format($product['retail_price'], 2, ',', ' ') }}</td>@endif
                    <td class="number">{{ $product['delivery_date']?->format($document['captions']['date_format']) ?? '-' }}</td>
                    <td class="number">{{ $product['catalog_page'] ?? '-' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="contact-block">
            @foreach ($document['footer_lines'] as $line)<p>{{ $line }}</p>@endforeach
        </div>
    </div>
@endforeach
</body>
</html>
