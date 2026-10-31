<section aria-labelledby="declarations-heading" class="space-y-4">
    <h2 id="declarations-heading" class="text-base font-semibold">{{ __('billing::admin.declarations.heading') }}</h2>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing::admin.declarations.intro') }}</p>

    {{-- Persistent live regions, like the console's: the wrappers stay in the DOM and only their text toggles, so a
         screen reader announces the change. --}}
    <div role="status" aria-live="polite">
        @if ($result === 'resolved')
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200">
                {{ __('billing::admin.declarations.resolved') }}
            </div>
        @endif
    </div>
    <div role="alert" aria-live="assertive">
        @if ($result === 'gone' || $result === 'invalid_day')
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ __('billing::admin.declarations.'.$result) }}
            </div>
        @endif
    </div>

    @if ($cancellations->isEmpty() && $withdrawals->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::admin.declarations.empty') }}</p>
    @endif

    @foreach (['cancellation' => $cancellations, 'withdrawal' => $withdrawals] as $kind => $declarations)
        @foreach ($declarations as $declaration)
            <article wire:key="{{ $kind }}-{{ $declaration->id }}" aria-labelledby="{{ $kind }}-{{ $declaration->id }}-heading"
                class="space-y-3 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h3 id="{{ $kind }}-{{ $declaration->id }}-heading" class="text-sm font-semibold">{{ __('billing::admin.declarations.'.$kind) }}</h3>

                {{-- The declaration as the person's confirmation states it, every value as they typed it. --}}
                <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                    @foreach ($kind === 'cancellation' ? \Pushery\Billing\Consumer\DeclarationStatement::cancellationLines($declaration) : \Pushery\Billing\Consumer\DeclarationStatement::withdrawalLines($declaration) as $line)
                        <dt class="text-gray-500 dark:text-gray-400">{{ $line['label'] }}</dt>
                        <dd class="break-words">{{ $line['value'] }}</dd>
                    @endforeach
                </dl>

                @if ($declaration->failure !== null)
                    <p class="text-sm text-amber-900 dark:text-amber-200">{{ __('billing::admin.declarations.failure', ['failure' => \Pushery\Billing\Consumer\DeclarationFailure::sentence($declaration->failure)]) }}</p>
                @endif

                {{-- One row from `sm` up, every control 40px tall, as the console's forms are. --}}
                @if ($kind === 'cancellation')
                    <form wire:submit="resolveCancellation({{ $declaration->id }})" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="declaration-ends-{{ $declaration->id }}" class="block text-sm font-medium">{{ __('billing::admin.declarations.ends_on') }}</label>
                            <input class="sm:h-10 mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base shadow-sm focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-900 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-gray-100"
                                type="date" id="declaration-ends-{{ $declaration->id }}" wire:model="endsOn.{{ $declaration->id }}">
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="resolveCancellation({{ $declaration->id }})"
                            class="rounded-lg sm:h-10 bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 disabled:opacity-50 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-gray-100 dark:focus:ring-offset-gray-950">
                            {{ __('billing::admin.declarations.resolve') }}
                        </button>
                    </form>
                @else
                    <form wire:submit="resolveWithdrawal({{ $declaration->id }})">
                        <button type="submit" wire:loading.attr="disabled" wire:target="resolveWithdrawal({{ $declaration->id }})"
                            class="rounded-lg sm:h-10 bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 disabled:opacity-50 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-gray-100 dark:focus:ring-offset-gray-950">
                            {{ __('billing::admin.declarations.resolve') }}
                        </button>
                    </form>
                @endif
            </article>
        @endforeach
    @endforeach
</section>
