<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.manage.heading') }}</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {{ __('billing::account.manage.current', ['plan' => $currentLabel]) }}
        </p>

        {{-- Trial-status note: how long is left, and (for a card-free trial) the hint to add one. --}}
        @if ($trial !== null)
            <p role="status" @class([
                'mt-3 rounded-lg p-3 text-sm font-medium',
                'bg-blue-50 text-blue-900 dark:bg-blue-950/40 dark:text-blue-100' => $trial->intent === 'info',
                'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100' => $trial->intent === 'warning',
            ])>
                {{ trans_choice($trial->messageKey, $trial->daysLeft, ['days' => $trial->daysLeft]) }}
            </p>
        @endif
    </header>

    {{-- Coupon entry: only for a visitor about to subscribe (a coupon applies at checkout, not to a swap).
         The code is validated live so the visitor sees it take before the hosted checkout. --}}
    @unless ($canSwap)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <label for="coupon-code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('billing::account.coupon.label') }}
            </label>
            <input id="coupon-code" type="text" wire:model.blur="couponCode" autocomplete="off"
                placeholder="{{ __('billing::account.coupon.placeholder') }}"
                class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
            @if ($couponStatus === 'applied')
                <p role="status" class="mt-2 text-sm font-medium text-green-700 dark:text-green-400">{{ __('billing::account.coupon.applied') }}</p>
            @elseif ($couponStatus === 'invalid')
                <p role="status" class="mt-2 text-sm font-medium text-red-700 dark:text-red-400">{{ __('billing::account.coupon.invalid') }}</p>
            @endif
        </div>
    @endunless

    <section class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @forelse ($options as $option)
            <div wire:key="plan-{{ $option['key'] }}" class="flex items-center justify-between p-4">
                <div>
                    <span class="font-medium">{{ $option['label'] }}</span>
                    <span class="ml-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ $option['price'] }} / {{ __('billing::account.interval.'.$option['interval']) }}
                    </span>
                    @if ($canSwap && $previewTierKey === $option['key'])
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            @if ($previewAmount !== null)
                                {{ __('billing::account.manage.preview_due', ['amount' => $previewAmount]) }}
                            @else
                                {{ __('billing::account.manage.preview_unavailable') }}
                            @endif
                        </p>
                    @endif
                    @unless ($canSwap)
                        @if ($trialDays !== null)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                {{ __('billing::account.manage.trial_days', ['days' => $trialDays]) }}
                            </p>
                        @endif
                    @endunless
                </div>
                <div class="flex items-center gap-2">
                    @if ($canSwap)
                        <button type="button" wire:click="preview('{{ $option['key'] }}')" wire:loading.attr="disabled"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                            {{ __('billing::account.manage.preview') }}
                        </button>
                        <button type="button" wire:click="swap('{{ $option['key'] }}')" wire:loading.attr="disabled"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">
                            {{ __('billing::account.manage.swap_to') }}
                        </button>
                    @else
                        <button type="button" wire:click="subscribe('{{ $option['key'] }}')" wire:loading.attr="disabled"
                            class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">
                            {{ __('billing::account.manage.subscribe') }}
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.manage.no_options') }}</p>
        @endforelse
    </section>
</div>
