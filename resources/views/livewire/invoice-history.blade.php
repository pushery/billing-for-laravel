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
            <table class="w-full text-left text-sm">
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
                            <td class="px-4 py-3">{{ $invoice->date->format('d.m.Y') }}</td>
                            <td class="px-4 py-3">{{ $invoice->number ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $invoice->total->format() }}</td>
                            <td class="px-4 py-3">
                                @php($intent = $invoice->status->badgeIntent())
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' => $intent === 'success',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' => $intent === 'info',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $intent === 'warning',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $intent === 'danger',
                                    'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $intent === 'neutral',
                                ])>
                                    {{ __('billing::account.invoice_status.'.$invoice->status->value) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($invoice->isDownloadable())
                                    <button type="button" wire:click="download('{{ $invoice->id }}')"
                                        class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                        {{ __('billing::account.invoices.download') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
