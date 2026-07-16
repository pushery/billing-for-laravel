<div class="space-y-6">
    <header class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ __('billing::account.payment_methods.heading') }}</h1>
        <button type="button" wire:click="addMethod" wire:loading.attr="disabled"
            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">
            {{ __('billing::account.payment_methods.add') }}
        </button>
    </header>

    @if ($degraded)
        {{-- A provider read failed to load; degrade to a notice instead of 500-ing the whole screen. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.degraded') }}
        </p>
    @endif

    <section class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @forelse ($methods as $method)
            <div wire:key="pm-{{ $method->id }}" class="flex items-center justify-between p-4">
                <div class="flex items-center gap-3">
                    <span class="font-medium">{{ $method->label() }}</span>
                    @if ($method->expMonth !== null && $method->expYear !== null)
                        @if ($method->hasExpired())
                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-200">
                                {{ __('billing::account.payment_methods.expired', ['date' => sprintf('%02d/%d', $method->expMonth, $method->expYear)]) }}
                            </span>
                        @elseif ($method->isExpiringWithin(30))
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ __('billing::account.payment_methods.expiring', ['date' => sprintf('%02d/%d', $method->expMonth, $method->expYear)]) }}
                            </span>
                        @else
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ sprintf('%02d/%d', $method->expMonth, $method->expYear) }}</span>
                        @endif
                    @endif
                    @if ($method->isDefault)
                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-200">
                            {{ __('billing::account.payment_methods.default') }}
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    @unless ($method->isDefault)
                        <button type="button" wire:click="setDefault('{{ $method->id }}')"
                            class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                            {{ __('billing::account.payment_methods.make_default') }}
                        </button>
                    @endunless
                    <button type="button" wire:click="remove('{{ $method->id }}')"
                        class="text-sm font-medium text-red-600 hover:underline dark:text-red-400">
                        {{ __('billing::account.payment_methods.remove') }}
                    </button>
                </div>
            </div>
        @empty
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.payment_methods.empty') }}</p>
        @endforelse
    </section>

</div>
