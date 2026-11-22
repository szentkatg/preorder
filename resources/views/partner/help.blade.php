<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $labels['title'] }}</title>
    <style>
        :root { color-scheme: light; font: 16px/1.7 system-ui, -apple-system, "Segoe UI", sans-serif; color: #173042; background: #f3f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        a { color: #036780; text-underline-offset: .2em; }
        a:focus-visible, summary:focus-visible { outline: 3px solid #0284c7; outline-offset: 4px; }
        .skip { position: absolute; left: 1rem; top: -6rem; background: white; padding: .5rem 1rem; z-index: 10; }
        .skip:focus { top: .5rem; }
        .toolbar { position: sticky; top: 0; z-index: 2; border-bottom: 1px solid #d6e2e8; background: #ffffffed; backdrop-filter: blur(10px); }
        .toolbar-inner { max-width: 1320px; margin: auto; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; padding: .75rem 1.5rem; }
        .brand { font-weight: 800; letter-spacing: .04em; font-size: .85rem; color: #164e63; }
        .tools { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
        .languages { display: flex; gap: .25rem; }
        .languages a { text-decoration: none; padding: .3rem .6rem; border-radius: .4rem; font-size: .85rem; }
        .languages a[aria-current="page"] { background: #e4f1f5; font-weight: 750; }
        .download { background: #164e63; color: white; text-decoration: none; border-radius: .5rem; padding: .5rem .9rem; font-weight: 650; font-size: .9rem; white-space: nowrap; }
        .shell { max-width: 1320px; padding: 2rem 1.5rem 4rem; margin: auto; display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 2rem; align-items: start; }
        .contents { position: sticky; top: 5.5rem; scroll-margin-top: 7rem; max-height: calc(100dvh - 7rem); overflow-y: auto; padding: 1rem; border: 1px solid #d6e2e8; background: white; border-radius: .75rem; font-size: .875rem; }
        .contents summary { font-weight: 750; cursor: pointer; color: #164e63; }
        .contents ol { margin: .75rem 0 0; padding-left: 1.4rem; }
        .contents li { padding: .25rem 0; }
        .contents a { text-decoration: none; }
        .contents a:hover { text-decoration: underline; }
        main { min-width: 0; }
        .intro { padding: .3rem .5rem 1.5rem; }
        h1 { font-size: clamp(1.8rem, 4vw, 2.7rem); line-height: 1.2; letter-spacing: -.025em; color: #164e63; margin: .25rem 0 1rem; }
        h2 { font-size: 1.45rem; line-height: 1.35; color: #164e63; margin: 0 0 1rem; }
        h3 { font-size: 1.05rem; margin: 1.25rem 0 .35rem; }
        p { margin: 0 0 1rem; }
        .muted { font-size: .875rem; color: #526977; }
        .chapter { scroll-margin-top: 7rem; background: white; border: 1px solid #d6e2e8; border-radius: .8rem; padding: 1.6rem; margin-bottom: 1.5rem; box-shadow: 0 3px 12px #17304205; overflow-wrap: anywhere; }
        .steps { padding-left: 1.6rem; }
        .steps li { padding-left: .25rem; margin-bottom: .65rem; }
        .steps li::marker { font-weight: 750; color: #0e7490; }
        figure { margin: 1.25rem 0; padding: .6rem; border: 1px solid #dde6eb; background: #f8fafb; border-radius: .6rem; }
        figure img { display: block; width: auto; height: auto; max-width: 100%; margin: auto; }
        figcaption { font-size: .82rem; line-height: 1.5; color: #526977; margin: .6rem .2rem .1rem; }
        .note { margin: 1.25rem 0; padding: 1rem 1.1rem; border-left: 4px solid #0284a3; border-radius: .3rem; background: #edf7fa; }
        .note p:last-child, .chapter > :last-child { margin-bottom: 0; }
        .table-scroll { overflow-x: auto; margin: 1rem 0; }
        table { width: 100%; border-collapse: collapse; font-size: .93rem; }
        th, td { text-align: left; border: 1px solid #cddce4; padding: .6rem .75rem; vertical-align: top; }
        th { background: #edf5f8; }
        .back-top { display: inline-block; font-size: .85rem; margin-top: .75rem; }
        @media (max-width: 900px) {
            .shell { display: block; padding: 1rem .8rem 2rem; }
            .contents { position: static; max-height: none; margin-bottom: 1.25rem; }
            .toolbar-inner { padding: .65rem .8rem; }
            .chapter { padding: 1.15rem; scroll-margin-top: 8rem; }
        }
        @media print { .toolbar, .contents, .back-top { display: none; } .shell { display: block; padding: 0; } .chapter { box-shadow: none; border: 0; padding: 0; } figure, .note { break-inside: avoid; } }
    </style>
</head>
<body>
    <a class="skip" href="#guide">{{ $labels['skip'] }}</a>
    <header class="toolbar">
        <div class="toolbar-inner">
            <span class="brand">{{ $labels['eyebrow'] }}</span>
            <div class="tools">
                <nav class="languages" aria-label="{{ $labels['language'] }}">
                    @foreach (['hu' => 'HU', 'en' => 'EN'] as $code => $name)
                        <a href="{{ route('partner.help', ['locale' => $code]) }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($code === $locale) aria-current="page" @endif>{{ $name }}</a>
                    @endforeach
                </nav>
                <a class="download" href="{{ route('partner.help.pdf', ['locale' => $locale]) }}" download data-help-pdf>{{ $labels['download'] }}</a>
            </div>
        </div>
    </header>
    <div class="shell">
        <details class="contents" id="contents" open>
            <summary>{{ $labels['contents'] }}</summary>
            <nav aria-label="{{ $labels['contents'] }}">
                <ol>
                    @foreach ($chapters as $chapter)
                        <li><a href="#chapter-{{ $loop->iteration }}">{{ $chapter['title'] }}</a></li>
                    @endforeach
                </ol>
            </nav>
        </details>
        <main id="guide" data-partner-help-guide>
            <div class="intro">
                <p class="muted">{{ $labels['updated'] }}</p>
                <h1>{{ $labels['heading'] }}</h1>
                <p>{{ $labels['intro'] }}</p>
                <p class="muted">{{ $labels['demo'] }}</p>
            </div>
            @foreach ($chapters as $chapter)
                <section id="chapter-{{ $loop->iteration }}" class="chapter" aria-labelledby="chapter-title-{{ $loop->iteration }}">
                    <h2 id="chapter-title-{{ $loop->iteration }}">{{ $loop->iteration }}. {{ $chapter['title'] }}</h2>
                    @if (isset($chapter['intro'])) <p>{{ $chapter['intro'] }}</p> @endif
                    @foreach ($chapter['illustrations'] as $image)
                        <figure>
                            <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
                                <img src="{{ $image['url'] }}" alt="{{ $image['caption'] }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" loading="lazy" decoding="async">
                            </a>
                            <figcaption>{{ $image['caption'] }}</figcaption>
                        </figure>
                    @endforeach
                    @if (! empty($chapter['steps']))
                        <ol class="steps">@foreach ($chapter['steps'] as $step) <li>{{ $step }}</li> @endforeach</ol>
                    @endif
                    @foreach ($chapter['paragraphs'] ?? [] as $paragraph) <p>{{ $paragraph }}</p> @endforeach
                    @if (! empty($chapter['table']))
                        <div class="table-scroll"><table>
                            <thead><tr>@foreach ($chapter['table'][0] as $heading) <th scope="col">{{ $heading }}</th> @endforeach</tr></thead>
                            <tbody>@foreach ($chapter['table'][1] as $row) <tr>@foreach ($row as $cell) <td>{{ $cell }}</td> @endforeach</tr> @endforeach</tbody>
                        </table></div>
                    @endif
                    @foreach ($chapter['faqs'] ?? [] as [$question, $answer]) <h3>{{ $question }}</h3><p>{{ $answer }}</p> @endforeach
                    @if (! empty($chapter['notes']))
                        <aside class="note"><strong>{{ $labels['note'] }}</strong>
                            @foreach ($chapter['notes'] as $note) <p>{{ $note }}</p> @endforeach
                        </aside>
                    @endif
                    @if (! empty($chapter['checklist']))
                        <h3>{{ $labels['checklist'] }}</h3>
                        <ul>@foreach ($chapter['checklist'] as $item) <li>{{ $item }}</li> @endforeach</ul>
                    @endif
                    <a class="back-top" href="#contents">{{ $labels['top'] }}</a>
                </section>
            @endforeach
            <p class="muted">{{ $labels['back'] }}</p>
        </main>
    </div>
    <script>
        const toc = document.getElementById('contents');
        const desktop = window.matchMedia('(min-width: 901px)');
        const adaptContents = () => { toc.open = desktop.matches; };
        adaptContents();
        desktop.addEventListener('change', adaptContents);
        document.querySelectorAll('a[href="#contents"]').forEach(link => link.addEventListener('click', () => { toc.open = true; }));
    </script>
</body>
</html>
