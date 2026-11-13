@php($intent = $state->badgeIntent())
{{-- While the subscription is still "activating" after checkout, poll (bounded) until it settles. The poll
     does not depend on realtime broadcasting: a broadcast notifies the owner and does not re-render this screen. --}}
<div class="space-y-6" @if ($poll) wire:poll.{{ $poll }}="activationTick" @endif>
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
        {{-- Status and plan as two facts, each its name beside its value, so the name of one is not a word in
             the sentence of the other. --}}
        <dl class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-sm">
            <dt class="text-gray-500 dark:text-gray-400">{{ __('billing::account.subscription.status') }}</dt>
            <dd>
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
            </dd>

            {{-- The plan the subscription is on, with its price where the catalog declares one. --}}
            @if ($plan !== null)
                <dt class="text-gray-500 dark:text-gray-400">{{ __('billing::account.subscription.plan_label') }}</dt>
                <dd class="text-gray-900 dark:text-gray-100">
                    @if ($plan['price'] !== null)
                        {{ __('billing::account.subscription.plan_priced', ['plan' => $plan['label'], 'price' => $plan['price'], 'interval' => __('billing::account.interval.'.$plan['interval'])]) }}
                    @else
                        {{ __('billing::account.subscription.plan', ['plan' => $plan['label']]) }}
                    @endif
                </dd>
            @endif
        </dl>

        {{-- When access ends (grace) or has ended, show the date — read from the local column, never a call. --}}
        @if ($endsAt !== null && $state->value === 'grace')
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                {{ __('billing::account.subscription.access_ends', ['date' => \Pushery\Billing\Support\LocalizedDate::long($endsAt)]) }}
            </p>
        @elseif ($endsAt !== null && $state->value === 'ended')
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                {{ __('billing::account.subscription.access_ended', ['date' => \Pushery\Billing\Support\LocalizedDate::long($endsAt)]) }}
            </p>
        @endif

        @if ($preview)
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('billing::account.subscription.next_invoice', [
                    'amount' => \Pushery\Billing\Support\LocalizedMoney::format($preview->amount),
                    'date' => \Pushery\Billing\Support\LocalizedDate::long($preview->date),
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
                {{-- The optional churn survey. It never blocks the cancellation — "prefer not to say" leaves
                     the reason empty and the button still cancels in one click. --}}
                <div class="flex w-full flex-col gap-3">
                    <label class="flex max-w-xs flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
                        <span>{{ __('billing::account.cancel_survey.prompt') }}</span>
                        {{-- The list draws its arrow itself rather than leaving it to the browser, so the arrow keeps
                             the distance from the edge the text keeps from the other one, in every engine. --}}
                        <span class="relative flex">
                            <select id="cancel-reason" wire:model.live="cancelReason"
                                class="w-full appearance-none rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-10 text-base sm:text-sm dark:border-gray-700 dark:bg-gray-900">
                                <option value="">{{ __('billing::account.cancel_survey.no_reason') }}</option>
                                @foreach (\Pushery\Billing\Enums\CancellationReason::cases() as $reason)
                                    <option value="{{ $reason->value }}">{{ __('billing::account.cancel_survey.reason.'.$reason->value) }}</option>
                                @endforeach
                            </select>
                            <svg aria-hidden="true" data-billing-select-arrow viewBox="0 0 20 20" fill="currentColor" class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-gray-500 dark:text-gray-400"><path fill-rule="evenodd" d="M 5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                        </span>
                    </label>

                    @if ($cancelReason === \Pushery\Billing\Enums\CancellationReason::Other->value)
                        <label class="flex max-w-xs flex-col gap-1 text-sm text-gray-600 dark:text-gray-300">
                            <span class="sr-only">{{ __('billing::account.cancel_survey.detail_label') }}</span>
                            <textarea wire:model="cancelDetail" rows="2" maxlength="1000"
                                placeholder="{{ __('billing::account.cancel_survey.detail_placeholder') }}"
                                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-base sm:text-sm dark:border-gray-700 dark:bg-gray-900"></textarea>
                        </label>
                    @endif

                    @error('cancelReason')
                        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    @error('cancelDetail')
                        <p role="alert" class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Beside the portal link, in the row that holds the screen's actions and wraps them only where
                     they do not fit, rather than under the survey on a line of its own. Red, as the one action
                     here that ends something. --}}
                @if ($confirmingCancel)
                    {{-- The armed state. Rendered inline rather than as a modal so it needs no JavaScript
                         and no dialog component: an install that re-skins this hub keeps its own chrome,
                         and the step still works with scripting off. --}}
                    <div role="group" aria-labelledby="billing-cancel-confirm" class="rounded-lg border border-gray-300 p-4 dark:border-gray-700">
                        <p id="billing-cancel-confirm" class="text-sm text-gray-900 dark:text-gray-100">
                            {{ __('billing::account.subscription.cancel_confirm_question') }}
                        </p>

                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="cancel" wire:loading.attr="disabled"
                                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50 dark:bg-red-600 dark:hover:bg-red-500">
                                {{ __('billing::account.subscription.cancel_confirm') }}
                            </button>

                            <button type="button" wire:click="abortCancel" wire:loading.attr="disabled"
                                class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50 bg-gray-200 text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ __('billing::account.subscription.cancel_abort') }}
                            </button>
                        </div>
                    </div>
                @else
                    <button type="button" wire:click="cancel" wire:loading.attr="disabled"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50 dark:bg-red-600 dark:hover:bg-red-500">
                        {{ __('billing::account.subscription.cancel') }}
                    </button>
                @endif
            @elseif ($state === \Pushery\Billing\Enums\SubscriptionState::Grace && $resumable)
                <button type="button" wire:click="resume" wire:loading.attr="disabled"
                    class="rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                    {{ __('billing::account.subscription.resume') }}
                </button>
            @elseif (in_array($state, [
                \Pushery\Billing\Enums\SubscriptionState::None,
                \Pushery\Billing\Enums\SubscriptionState::Churned,
                \Pushery\Billing\Enums\SubscriptionState::Ended,
            ], true) || ($state === \Pushery\Billing\Enums\SubscriptionState::Grace && $subscribesAnew))
                <a href="{{ route('billing.account.plan') }}"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900">
                    {{ __('billing::account.banner.cta.upgrade') }}
                </a>
            @endif

            @if ($supportsHostedPortal && \Illuminate\Support\Facades\Route::has('billing.account.portal'))
                <a href="{{ route('billing.account.portal') }}"
                    class="inline-flex self-start rounded-lg px-4 py-2 text-sm font-medium bg-gray-200 text-gray-900 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                    {{ __('billing::account.subscription.portal') }}
                </a>
            @endif
        </div>
    </section>

    @if ($credit !== null)
        <section class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-900/50 dark:bg-emerald-950/30">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                {{ __('billing::account.credit.balance', ['amount' => \Pushery\Billing\Support\LocalizedMoney::format($credit)]) }}
            </p>
            <p class="mt-2 text-sm text-emerald-800 dark:text-emerald-200">
                {{ __('billing::account.credit.explanation') }}
            </p>
        </section>
    @endif
</div>
