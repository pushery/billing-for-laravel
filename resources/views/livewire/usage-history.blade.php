<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.usage_history.heading') }}</h1>
    </header>

    @if ($degraded)
        {{-- A history read failed — degrade this screen to a notice, not a 500 for the whole hub. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.usage_history.unavailable') }}
        </p>
    @elseif ($byPeriod === [] && $topups === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.usage_history.empty') }}</p>
    @else
        @if ($byPeriod !== [])
            <section class="space-y-4" aria-label="{{ __('billing::account.usage_history.periods_heading') }}">
                <h2 class="text-lg font-medium">{{ __('billing::account.usage_history.periods_heading') }}</h2>

                @foreach ($byPeriod as $period => $rows)
                    <div wire:key="period-{{ $period }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <p class="font-medium">{{ $period }}</p>

                        <dl class="mt-2 space-y-1">
                            @foreach ($rows as $row)
                                <div wire:key="period-{{ $period }}-{{ $row->meterKey }}" class="flex items-center justify-between text-sm">
                                    <dt class="text-gray-600 dark:text-gray-300">{{ $row->meterKey }}</dt>
                                    <dd class="text-gray-500 dark:text-gray-400">
                                        {{ __('billing::account.usage_history.used', ['used' => $row->used]) }}
                                        @if ($row->prepaidUsed > 0)
                                            <span class="text-xs">({{ __('billing::account.usage_history.prepaid_used', ['units' => $row->prepaidUsed]) }})</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($topups !== [])
            <section class="space-y-3" aria-label="{{ __('billing::account.usage_history.topups_heading') }}">
                <h2 class="text-lg font-medium">{{ __('billing::account.usage_history.topups_heading') }}</h2>

                <ul class="space-y-2">
                    @foreach ($topups as $index => $topup)
                        <li wire:key="topup-{{ $index }}" class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <span class="font-medium">{{ $topup->addonKey }}</span>
                            <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                <span>{{ $topup->amount->format() }}</span>
                                <span class="text-xs">{{ $topup->purchasedAt->format('Y-m-d') }}</span>
                                @if ($topup->reversed)
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        {{ __('billing::account.usage_history.reversed') }}
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
