<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('billing::admin.title') }}</title>
    @if (config('account.stylesheet'))
        <link rel="stylesheet" href="{{ config('account.stylesheet') }}">
    @endif
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
    {{-- Skip link: a keyboard or screen-reader user jumps straight past the header to the content. --}}
    <a href="#main-content"
        class="sr-only rounded-lg bg-white px-4 py-2 text-sm font-medium shadow focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:outline-none focus:ring-2 focus:ring-gray-900 dark:bg-gray-900 dark:focus:ring-gray-100">
        {{ __('billing::account.skip_to_content') }}
    </a>

    {{-- A deliberately minimal admin shell — NOT the customer account nav. It is plain, framework-agnostic
         markup so the package needs no UI-kit dependency; publish `billing-views` to replace it wholesale
         (e.g. with your own back-office app shell). --}}
    <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-8">
        <header class="flex items-center justify-between border-b border-gray-200 pb-4 dark:border-gray-800">
            <h1 class="text-lg font-semibold">{{ __('billing::admin.title') }}</h1>
            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                {{ __('billing::admin.badge') }}
            </span>
        </header>

        {{-- tabindex="-1" so activating the skip link moves keyboard/screen-reader focus into the content,
             not just the viewport scroll — otherwise focus stays on the skip link (SC 2.4.1). --}}
        <main id="main-content" tabindex="-1" class="focus:outline-none">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
