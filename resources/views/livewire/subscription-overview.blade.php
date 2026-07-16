@php($intent = $state->badgeIntent())
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.subscription.heading') }}</h1>
    </header>

    @if ($degraded)
        {{-- A provider read failed to load; degrade to a notice instead of 500-ing the whole screen. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.degraded') }}
        </p>
    @endif

    <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.subscription.status') }}</span>
            <span @class([
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-medium',
                'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' => $intent === 'success',
                'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' => $intent === 'info',
                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $intent === 'warning',
                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $intent === 'danger',
                'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $intent === 'neutral',
            ])>
                {{ __('billing::account.state.'.$state->value) }}
            </span>
        </div>

        @if ($preview)
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('billing::account.subscription.next_invoice', [
                    'amount' => $preview->amount->format(),
                    'date' => $preview->date->format('d.m.Y'),
                ]) }}
            </p>
        @endif

        {{-- The one trial CTA: shown only while trialing, and no other state CTA renders for a trial state,
             so a trialing owner sees exactly one next step. --}}
        @if ($trial !== null)
            <div role="status" @class([
                'mt-6 flex flex-wrap items-center justify-between gap-3 rounded-lg p-4',
                'bg-blue-50 text-blue-900 dark:bg-blue-950/40 dark:text-blue-100' => $trial->intent === 'info',
                'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100' => $trial->intent === 'warning',
            ])>
                <p class="text-sm font-medium">
                    {{ trans_choice($trial->messageKey, $trial->daysLeft, ['days' => $trial->daysLeft]) }}
                </p>
                <a href="{{ route($trial->ctaRoute) }}"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900">
                    {{ __($trial->ctaKey) }}
                </a>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center gap-3">
            @if ($state === \Pushery\Billing\Enums\SubscriptionState::Active)
                <button type="button" wire:click="cancel" wire:loading.attr="disabled"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">
                    {{ __('billing::account.subscription.cancel') }}
                </button>
            @elseif ($state === \Pushery\Billing\Enums\SubscriptionState::Grace)
                <button type="button" wire:click="resume" wire:loading.attr="disabled"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-500 disabled:opacity-50">
                    {{ __('billing::account.subscription.resume') }}
                </button>
            @elseif (in_array($state, [
                \Pushery\Billing\Enums\SubscriptionState::None,
                \Pushery\Billing\Enums\SubscriptionState::Churned,
                \Pushery\Billing\Enums\SubscriptionState::Ended,
            ], true))
                <a href="{{ route('billing.account.plan') }}"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900">
                    {{ __('billing::account.banner.cta.upgrade') }}
                </a>
            @endif

            @if (\Illuminate\Support\Facades\Route::has('billing.account.portal'))
                <a href="{{ route('billing.account.portal') }}"
                    class="text-sm font-medium text-gray-600 underline hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                    {{ __('billing::account.subscription.portal') }}
                </a>
            @endif
        </div>
    </section>

    @if ($credit !== null)
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900/50 dark:bg-emerald-950/30">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                {{ __('billing::account.credit.balance', ['amount' => $credit->format()]) }}
            </p>
            <p class="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                {{ __('billing::account.credit.explanation') }}
            </p>
        </section>
    @endif
</div>
