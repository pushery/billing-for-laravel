<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.manage.heading') }}</h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            {{ __('billing::account.manage.current', ['plan' => $currentLabel]) }}
        </p>

        {{-- A scheduled downgrade the customer has not seen take effect yet: shown with its date and a
             cancel action, so a pending change is never a surprise on the next invoice. --}}
        @if ($scheduledSwap !== null)
            <div role="status" class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm dark:border-amber-900 dark:bg-amber-950">
                <span class="text-amber-800 dark:text-amber-200">
                    {{ __('billing::account.manage.scheduled_swap', ['plan' => $scheduledSwap['tierLabel'], 'date' => $scheduledSwap['date']]) }}
                </span>
                <button type="button" wire:click="cancelScheduledSwap" wire:loading.attr="disabled"
                    class="shrink-0 rounded-md bg-amber-700 px-3 py-1 font-medium text-white hover:bg-amber-800 disabled:opacity-50">
                    {{ __('billing::account.manage.scheduled_swap_cancel') }}
                </button>
            </div>
        @endif

        {{-- A consumer's annual term: when it ends and what follows it, and in its last 60 days a further year the
             customer orders by ticking its wording. The wording is the order, so the box is never ticked for them. --}}
        @if ($annualTerm !== null)
            <div class="mt-3 space-y-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-100">
                <p>
                    @if ($annualTerm['booked'] !== null)
                        {{ __('billing::account.manage.annual_term.booked', ['date' => $annualTerm['booked']]) }}
                    @elseif ($annualTerm['monthly'] !== null)
                        {{ __('billing::account.manage.annual_term.ends_monthly', ['date' => $annualTerm['ends'], 'price' => $annualTerm['monthly']]) }}
                    @else
                        {{ __('billing::account.manage.annual_term.ends_twelfth', ['date' => $annualTerm['ends']]) }}
                    @endif
                </p>

                @if ($annualTerm['furtherYear'] !== null)
                    <form wire:submit="bookFurtherYear" class="space-y-2">
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="furtherYearConfirmed" class="mt-0.5 rounded border-blue-300 dark:border-blue-800">
                            <span>{{ $annualTerm['furtherYear'] }}</span>
                        </label>
                        @error('furtherYearConfirmed')
                            <p role="alert" class="font-medium text-red-700 dark:text-red-400">{{ $message }}</p>
                        @enderror
                        <button type="submit" wire:loading.attr="disabled"
                            class="rounded-md bg-blue-600 px-3 py-1 font-medium text-white hover:bg-blue-500 disabled:opacity-50">
                            {{ __('billing::account.manage.annual_term.further_year_action') }}
                        </button>
                    </form>
                @endif
            </div>
        @endif

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

        {{-- The card a swap/subscription will charge, mirrored from local columns — never a provider call. --}}
        @if ($cardOnFile !== null)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('billing::account.manage.card_on_file', ['brand' => \Pushery\Billing\Support\PaymentMethodNames::brand($cardOnFile['brand']), 'last4' => $cardOnFile['last4']]) }}
            </p>
        @endif
    </header>

    @if ($linkOut !== null)
        {{-- External merchant of record: billing is managed off-site, so the hub links out instead of
             offering in-app checkout it is not the merchant of record for. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing::account.manage.link_out.body') }}</p>
            <a href="{{ $linkOut }}" target="_blank" rel="noopener noreferrer"
                class="mt-4 inline-flex rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900">
                {{ __('billing::account.manage.link_out.action') }}
            </a>
        </div>
    @else
    {{-- Coupon entry: only for a visitor about to subscribe (a coupon applies at checkout, not to a swap).
         The code is validated live so the visitor sees it take before the hosted checkout. --}}
    @unless ($canSwap)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <label for="coupon-code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ __('billing::account.coupon.label') }}
            </label>
            <input id="coupon-code" type="text" wire:model.blur="couponCode" autocomplete="off"
                placeholder="{{ __('billing::account.coupon.placeholder') }}"
                class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
            @if ($couponStatus === 'applied')
                <p role="status" class="mt-2 text-sm font-medium text-green-700 dark:text-green-400">{{ __('billing::account.coupon.applied') }}</p>
            @elseif ($couponStatus === 'invalid')
                <p role="status" class="mt-2 text-sm font-medium text-red-700 dark:text-red-400">{{ __('billing::account.coupon.invalid') }}</p>
            @endif
        </div>
    @endunless

    @if ($intervals !== [])
        <div class="flex flex-wrap items-center gap-3">
            <span id="billing-period-label" class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing::account.manage.billing_period') }}</span>
            <div role="group" aria-labelledby="billing-period-label"
                class="inline-flex gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-900">
                @foreach ($intervals as $choice)
                    <button type="button" aria-pressed="{{ $choice['selected'] ? 'true' : 'false' }}"
                        wire:click="$set('interval', '{{ $choice['value'] }}')" wire:key="interval-{{ $choice['value'] }}"
                        class="rounded-md px-3 py-1.5 text-sm font-medium {{ $choice['selected'] ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800' }}">
                        {{ $choice['label'] }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- A change the provider holds until its charge is paid, where it offered no page to pay on, or one asked of a
         subscription that rests. --}}
    @error('swap')
        <p role="alert" class="mb-3 rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">{{ $message }}</p>
    @enderror

    {{-- A paused subscription is offered no plan change until it goes on, so the rows below carry no action then. --}}
    @if ($canSwap && $paused)
        <p role="status" class="mb-3 rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">{{ __('billing::account.manage.swap_while_paused') }}</p>
    @endif

    <section class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
        @forelse ($options as $option)
            <div wire:key="plan-{{ $option['key'] }}-{{ $option['interval'] }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
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
                {{-- Below sm the actions share the width of the row. From sm up the row is only as wide as its labels,
                     and an equal share of it would wrap the longer one, so each button keeps the width of its own label. --}}
                <div class="flex w-full gap-2 sm:w-auto sm:items-center">
                    @if ($canSwap)
                        @unless ($paused)
                            <button type="button" wire:click="preview('{{ $option['key'] }}')" wire:loading.attr="disabled"
                                class="flex-1 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50 sm:flex-none bg-gray-200 text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ __('billing::account.manage.preview') }}
                            </button>
                            <button type="button" wire:click="swap('{{ $option['key'] }}')" wire:loading.attr="disabled"
                                class="flex-1 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 sm:flex-none dark:bg-white dark:text-gray-900">
                                {{ __('billing::account.manage.swap_to') }}
                            </button>
                        @endunless
                    @else
                        <button type="button" wire:click="subscribe('{{ $option['key'] }}')" wire:loading.attr="disabled"
                            class="flex-1 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 sm:flex-none dark:bg-white dark:text-gray-900">
                            {{ __('billing::account.manage.subscribe') }}
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.manage.no_options') }}</p>
        @endforelse
    </section>

    {{-- One-time add-ons (top-ups). The #addons anchor is where the usage screen's top-up CTA lands. --}}
    @if ($addons !== [])
        <section id="addons" class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white shadow-sm dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
            <h2 class="p-4 text-lg font-medium">{{ __('billing::account.manage.addons_heading') }}</h2>

            @foreach ($addons as $addon)
                <div wire:key="addon-{{ $addon['key'] }}" class="flex items-center justify-between p-4">
                    <div>
                        <p class="font-medium">{{ $addon['label'] }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $addon['price'] }}</p>
                    </div>
                    <button type="button" wire:click="purchaseAddon('{{ $addon['key'] }}')"
                        class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-500">
                        {{ __('billing::account.manage.addon_buy') }}
                    </button>
                </div>
            @endforeach
        </section>
    @endif
    @endif

    @include('billing::components.hosted-page-frame')
</div>
