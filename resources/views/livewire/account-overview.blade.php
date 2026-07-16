<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.overview.heading') }}</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {{ __('billing::account.overview.current_plan', ['plan' => $tierLabel]) }}
        </p>
    </header>

    @if (count($items) > 0)
        <nav class="grid gap-3 sm:grid-cols-2">
            @foreach ($items as $item)
                <a wire:key="nav-{{ $item->key }}" href="{{ route($item->route) }}" wire:navigate
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-medium shadow-sm hover:border-gray-300 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700">
                    {{ __($item->label) }}
                </a>
            @endforeach
        </nav>
    @endif
</div>
