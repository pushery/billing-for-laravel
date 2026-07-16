<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.usage.heading') }}</h1>
    </header>

    {{-- While trialing, the usage below is the trial tier's entitlement — say so, without a competing CTA. --}}
    @if ($trial !== null)
        <p role="status" class="rounded-lg bg-blue-50 p-3 text-sm font-medium text-blue-900 dark:bg-blue-950/40 dark:text-blue-100">
            {{ trans_choice('billing::account.trial.usage', $trial->daysLeft, ['days' => $trial->daysLeft]) }}
        </p>
    @endif

    @if ($degraded)
        {{-- The UsageProvider failed to load — degrade this panel to a notice, not a 500 for the whole hub. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.usage.unavailable') }}
        </p>
    @elseif ($snapshot->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.usage.unmetered') }}</p>
    @else
        <section class="space-y-4">
            @foreach ($snapshot->dimensions as $dimension)
                <div wire:key="dim-{{ $dimension->key }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ $dimension->label }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $dimension->used }}@if ($dimension->limit !== null) / {{ $dimension->limit }}@endif {{ $dimension->unit }}
                        </span>
                    </div>

                    @if (($prepaid[$dimension->key] ?? 0) > 0)
                        {{-- Bought units that roll over across cycles, distinct from the per-cycle included allowance. --}}
                        <p class="mt-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('billing::account.usage.prepaid', ['units' => $prepaid[$dimension->key], 'unit' => $dimension->unit]) }}
                        </p>
                    @endif

                    @php($overIntent = $dimension->policy->overBandIntent())
                    @if ($dimension->limit !== null)
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                            <div @class([
                                'h-full rounded-full',
                                // Over the band: a hard/refuse limit reads as danger, a degrade as warning,
                                // a fair-use (soft) limit stays neutral — it is information, not a wall.
                                'bg-red-500' => $dimension->isOver() && $overIntent === 'danger',
                                'bg-amber-500' => ($dimension->isOver() && $overIntent === 'warning') || (! $dimension->isOver() && $dimension->isWarning()),
                                'bg-gray-400' => $dimension->isOver() && $overIntent === 'neutral',
                                'bg-green-500' => ! $dimension->isOver() && ! $dimension->isWarning(),
                            ]) style="width: {{ $dimension->percent() }}%"></div>
                        </div>
                    @endif

                    @if ($dimension->isOver())
                        <p @class([
                            'mt-2 text-xs font-medium',
                            'text-red-600 dark:text-red-400' => $overIntent === 'danger',
                            'text-amber-600 dark:text-amber-400' => $overIntent === 'warning',
                            'text-gray-500 dark:text-gray-400' => $overIntent === 'neutral',
                        ])>
                            {{ __('billing::account.usage.'.($overIntent === 'neutral' ? 'over_soft' : 'over')) }}
                        </p>
                    @elseif ($dimension->isWarning())
                        <p class="mt-2 text-xs font-medium text-amber-600 dark:text-amber-400">{{ __('billing::account.usage.warning') }}</p>
                    @endif

                    {{-- Policy-driven remedy: a blocking dimension can only be relieved by upgrading to a higher
                         ceiling; a degrading/soft one is topped up with more units. The CTA appears once the
                         dimension is warning or over, and never for a comfortable one. --}}
                    @if ($dimension->isOver() || $dimension->isWarning())
                        @php($remedy = $dimension->policy->overRemedy())
                        <a href="{{ $remedy === 'upgrade' ? route('billing.account.plan') : route('billing.account.plan').'#addons' }}"
                            class="mt-2 inline-flex text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                            {{ $remedy === 'upgrade' ? __('billing::account.usage.cta_upgrade') : __('billing::account.usage.cta_topup') }}
                        </a>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
</div>
