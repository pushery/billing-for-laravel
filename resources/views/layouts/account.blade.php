<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('billing::account.title') }}</title>
    @if (config('account.stylesheet'))
        <link rel="stylesheet" href="{{ config('account.stylesheet') }}">
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    <main class="mx-auto max-w-3xl px-4 py-10">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
