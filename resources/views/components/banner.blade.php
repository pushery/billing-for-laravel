@php
    $tone = match ($notice->intent) {
        'danger' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
        default => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-200',
    };
@endphp

<div role="alert" class="flex items-center justify-between gap-4 rounded-xl border px-4 py-3 text-sm {{ $tone }}">
    <span>{{ __($notice->messageKey) }}</span>
    @if (Route::has($notice->ctaRoute))
        <a href="{{ route($notice->ctaRoute) }}" class="shrink-0 font-medium underline underline-offset-2">
            {{ __($notice->ctaKey) }}
        </a>
    @endif
</div>
