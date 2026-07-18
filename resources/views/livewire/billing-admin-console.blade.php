<div class="space-y-10">
    {{-- Metrics ------------------------------------------------------------------------------------------ --}}
    <section aria-labelledby="metrics-heading" class="space-y-4">
        <h2 id="metrics-heading" class="text-base font-semibold">{{ __('billing::admin.metrics.heading') }}</h2>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['label' => __('billing::admin.metrics.mrr'), 'value' => $metrics->mrr->format()],
                ['label' => __('billing::admin.metrics.active'), 'value' => $metrics->activeSubscriptions],
                ['label' => __('billing::admin.metrics.trials'), 'value' => $metrics->trials],
                ['label' => __('billing::admin.metrics.dunning'), 'value' => $metrics->inDunning],
                ['label' => __('billing::admin.metrics.churned', ['days' => $metrics->windowDays]), 'value' => $metrics->canceledInWindow],
            ] as $card)
                <div wire:key="metric-{{ $loop->index }}"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $card['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    {{-- Comp a tier (a support grant) ------------------------------------------------------------------- --}}
    <section aria-labelledby="comp-heading" class="space-y-4">
        <h2 id="comp-heading" class="text-base font-semibold">{{ __('billing::admin.comp.heading') }}</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing::admin.comp.intro') }}</p>

        {{-- Persistent live regions: the wrappers stay in the DOM, and only their text toggles between empty
             and the message. Livewire's morph then patches a real content change, which a screen reader
             announces — the reliable pattern for SC 4.1.3 (a node inserted together with its text, or a
             region whose text never changes, is announced unreliably). Success is polite, errors assertive. --}}
        <div role="status" aria-live="polite">
            @if ($compResult === 'granted')
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200">
                    {{ __('billing::admin.comp.granted') }}
                </div>
            @endif
        </div>
        <div role="alert" aria-live="assertive">
            @if ($compResult === 'not_found')
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                    {{ __('billing::admin.comp.not_found') }}
                </div>
            @elseif ($compResult === 'invalid_tier')
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                    {{ __('billing::admin.comp.invalid_tier') }}
                </div>
            @endif
        </div>

        <form wire:submit="comp" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="comp-owner" class="block text-sm font-medium">
                    {{ __('billing::admin.comp.owner_id') }} <span aria-hidden="true" class="text-red-600 dark:text-red-400">*</span>
                </label>
                {{-- text-base on mobile (≥16px) avoids iOS Safari's zoom-on-focus; the focus ring is an explicit,
                     high-contrast focus indicator so it does not rely on the fragile UA default outline. --}}
                <input id="comp-owner" type="text" wire:model="compOwnerId" required
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base shadow-sm focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-900 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-gray-100">
            </div>
            <div class="flex-1">
                <label for="comp-tier" class="block text-sm font-medium">
                    {{ __('billing::admin.comp.tier') }} <span aria-hidden="true" class="text-red-600 dark:text-red-400">*</span>
                </label>
                <input id="comp-tier" type="text" wire:model="compTier" required
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base shadow-sm focus:border-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-900 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:focus:ring-gray-100">
            </div>
            <button type="submit"
                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus:ring-gray-100 dark:focus:ring-offset-gray-950">
                {{ __('billing::admin.comp.submit') }}
            </button>
        </form>
    </section>

    {{-- Audit viewer ------------------------------------------------------------------------------------ --}}
    <section aria-labelledby="audit-heading" class="space-y-4">
        <h2 id="audit-heading" class="text-base font-semibold">{{ __('billing::admin.audit.heading') }}</h2>

        @if ($events->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing::admin.audit.empty') }}</p>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('billing::admin.audit.type') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('billing::admin.audit.source') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('billing::admin.audit.subject') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('billing::admin.audit.when') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($events as $event)
                            <tr wire:key="event-{{ $event->id }}">
                                <td class="px-4 py-3 font-mono text-xs">{{ $event->type }}</td>
                                <td class="px-4 py-3">{{ __('billing::admin.source.'.$event->source->value) }}</td>
                                <td class="px-4 py-3">
                                    {{ $event->subject_type ? class_basename($event->subject_type).' #'.$event->subject_id : '—' }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $event->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
