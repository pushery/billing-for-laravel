<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">{{ __('billing::account.payment_methods.heading') }}</h1>
        <button type="button" wire:click="addMethod" wire:loading.attr="disabled"
            class="whitespace-nowrap rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">
            {{ __('billing::account.payment_methods.add') }}
        </button>
    </header>

    @if ($degraded)
        {{-- A provider read failed to load; degrade to a notice instead of 500-ing the whole screen. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.degraded') }}
        </p>
    @endif

    @error('remove')
        {{-- The default-card guard refused a removal (removing the card a live subscription is billed to). --}}
        <p role="alert" class="rounded-lg bg-red-50 p-3 text-sm font-medium text-red-900 dark:bg-red-950/40 dark:text-red-100">
            {{ $message }}
        </p>
    @enderror

    <section class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @forelse ($methods as $method)
            {{-- Below sm the row stacks: the card and its badges above, the actions below, each with the
                 width of the row. From sm up both share one line. --}}
            <div wire:key="pm-{{ $method->id }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="font-medium">{{ $method->label() }}</span>
                    @if ($method->expMonth !== null && $method->expYear !== null)
                        @if ($method->hasExpired())
                            <span class="inline-flex items-center whitespace-nowrap rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-200">
                                {{ __('billing::account.payment_methods.expired', ['date' => sprintf('%02d/%d', $method->expMonth, $method->expYear)]) }}
                            </span>
                        @elseif ($method->isExpiringWithin(30))
                            <span class="inline-flex items-center whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ __('billing::account.payment_methods.expiring', ['date' => sprintf('%02d/%d', $method->expMonth, $method->expYear)]) }}
                            </span>
                        @else
                            <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ sprintf('%02d/%d', $method->expMonth, $method->expYear) }}</span>
                        @endif
                    @endif
                    @if ($method->isDefault)
                        <span class="inline-flex items-center whitespace-nowrap rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-200">
                            {{ __('billing::account.payment_methods.default') }}
                        </span>
                    @endif
                    @if ($method->isFallback)
                        <span class="inline-flex items-center whitespace-nowrap rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">
                            {{ __('billing::account.payment_methods.fallback') }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @unless ($method->isDefault)
                        <button type="button" wire:click="setDefault('{{ $method->id }}')"
                            class="whitespace-nowrap rounded-lg bg-gray-200 px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                            {{ __('billing::account.payment_methods.make_default') }}
                        </button>
                    @endunless
                    {{-- The fallback is offered only where the driver charges one, and never on the default. --}}
                    @if ($keepsFallback && ! $method->isDefault)
                        @if ($method->isFallback)
                            <button type="button" wire:click="clearFallback"
                                class="whitespace-nowrap rounded-lg bg-gray-200 px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ __('billing::account.payment_methods.clear_fallback') }}
                            </button>
                        @else
                            <button type="button" wire:click="setFallback('{{ $method->id }}')"
                                class="whitespace-nowrap rounded-lg bg-gray-200 px-3 py-1.5 text-sm font-medium text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ __('billing::account.payment_methods.make_fallback') }}
                            </button>
                        @endif
                    @endif
                    <button type="button" wire:click="remove('{{ $method->id }}')"
                        class="whitespace-nowrap rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500 dark:bg-red-600 dark:hover:bg-red-500">
                        {{ __('billing::account.payment_methods.remove') }}
                    </button>
                </div>
            </div>
        @empty
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.payment_methods.empty') }}</p>
        @endforelse
    </section>

</div>
