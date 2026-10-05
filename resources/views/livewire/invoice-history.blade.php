<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-semibold">{{ __('billing::account.invoices.heading') }}</h1>
    </header>

    @if ($degraded)
        {{-- A provider read failed to load; degrade to a notice instead of 500-ing the whole screen. --}}
        <p role="status" class="rounded-lg bg-amber-50 p-3 text-sm font-medium text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('billing::account.degraded') }}
        </p>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        @if ($page->isEmpty())
            <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('billing::account.invoices.empty') }}</p>
        @else
            {{-- Below sm each invoice is a card. The table's five columns need far more than a phone's width, and
                 the download link, the screen's one action, would sit past the edge of the screen. --}}
            <ul class="divide-y divide-gray-100 sm:hidden dark:divide-gray-800">
                @foreach ($page->rows as $invoice)
                    <li wire:key="invoice-card-{{ $invoice->id }}" class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                        <div class="min-w-0 space-y-1">
                            <p class="break-all font-medium">{{ $invoice->number ?? '—' }}</p>
                            <p class="text-gray-500 dark:text-gray-400">{{ \Pushery\Billing\Support\LocalizedDate::short($invoice->date) }} · {{ \Pushery\Billing\Support\LocalizedMoney::format($invoice->total) }}</p>
                            @include('billing::components.invoice-status', ['status' => $invoice->status])
                        </div>
                        @if ($invoice->isDownloadable())
                            <a href="{{ route('billing.account.invoice-download', $invoice->id) }}"
                                class="shrink-0 text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                {{ __('billing::account.invoices.download') }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>

            <table class="hidden w-full text-left text-sm sm:table">
                <thead class="border-b border-gray-200 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('billing::account.invoices.date') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('billing::account.invoices.number') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('billing::account.invoices.amount') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('billing::account.invoices.status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($page->rows as $invoice)
                        <tr wire:key="invoice-{{ $invoice->id }}">
                            <td class="px-4 py-3">{{ \Pushery\Billing\Support\LocalizedDate::short($invoice->date) }}</td>
                            <td class="px-4 py-3">{{ $invoice->number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ \Pushery\Billing\Support\LocalizedMoney::format($invoice->total) }}</td>
                            <td class="px-4 py-3">
                                @include('billing::components.invoice-status', ['status' => $invoice->status])
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($invoice->isDownloadable())
                                    {{-- A plain href to a dedicated, bookmarkable route — works without JS,
                                         and the route owner-checks the id before streaming anything. --}}
                                    <a href="{{ route('billing.account.invoice-download', $invoice->id) }}"
                                        class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                        {{ __('billing::account.invoices.download') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($page->hasMore)
                {{-- Widen the read to pull older invoices, up to the provider's cap. --}}
                <div class="border-t border-gray-200 p-4 text-center dark:border-gray-800">
                    <button type="button" wire:click="loadOlder"
                        class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                        {{ __('billing::account.invoices.load_older') }}
                    </button>
                </div>
            @endif
        @endif
    </section>
</div>
