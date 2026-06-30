<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner portál</title>

    <script src="https://cdn.tailwindcss.com"></script>

    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50">
    <div
        class="fixed inset-0 bg-cover bg-center opacity-20 pointer-events-none"
        style="background-image: url('{{ asset('images/partner_bg.jpg') }}');"
    ></div>

    <div class="relative z-10">
        {{ $slot }}
    </div>

    @livewireScripts
</body>
</html>