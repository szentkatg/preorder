<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('partner.portal_unavailable_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f1f5f9; color: #0f172a; font: 16px/1.6 system-ui, sans-serif; }
        main { box-sizing: border-box; width: min(40rem, calc(100% - 2rem)); padding: 2rem; margin: 2rem 0; background: white; border: 1px solid #cbd5e1; border-top: 5px solid #eab308; border-radius: .75rem; box-shadow: 0 8px 24px #0f172a0d; }
        h1 { font-size: 1.5rem; line-height: 1.3; margin: 0 0 1rem; }
        p { white-space: pre-wrap; overflow-wrap: anywhere; }
        button { margin-top: .5rem; background: #0f172a; color: white; padding: .6rem 1rem; border: 0; border-radius: .375rem; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <main role="main">
        <h1>{{ __('partner.portal_unavailable_title') }}</h1>
        <p>{{ filled($message) ? $message : __('partner.portal_unavailable_default_message') }}</p>
        <button type="button" onclick="window.location.reload()">{{ __('partner.portal_retry') }}</button>
    </main>
</body>
</html>
