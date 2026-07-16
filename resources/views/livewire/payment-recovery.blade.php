<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.recovery.heading') }}</h1>
    </header>

    @if ($degraded)
        {{-- A provider read failed to load; degrade to a notice instead of 500-ing the whole screen. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.degraded') }}
        </p>
    @endif

    @if ($needsRecovery)
        <section class="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-900/50 dark:bg-red-950/30">
            <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ __('billing::account.recovery.failed') }}</p>

            <p class="mt-3 text-sm text-red-800 dark:text-red-200">
                @if ($default !== null)
                    {{ __('billing::account.recovery.current_method', ['method' => $default->label()]) }}
                @else
                    {{ __('billing::account.recovery.no_method') }}
                @endif
            </p>

            <button type="button" wire:click="updatePaymentMethod" wire:loading.attr="disabled"
                class="mt-4 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50">
                {{ __('billing::account.recovery.update') }}
            </button>
        </section>
    @elseif ($needsConfirmation)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-900/50 dark:bg-amber-950/30">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">{{ __('billing::account.recovery.incomplete') }}</p>

            <p class="mt-3 text-sm text-amber-800 dark:text-amber-200">
                {{ __('billing::account.recovery.incomplete_hint') }}
            </p>

            <button type="button" wire:click="updatePaymentMethod" wire:loading.attr="disabled"
                class="mt-4 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-500 disabled:opacity-50">
                {{ __('billing::account.recovery.confirm') }}
            </button>
        </section>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.recovery.all_good') }}</p>
    @endif
</div>
