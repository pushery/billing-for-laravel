{{--
    The package's own invoice document — the local counterpart to a provider's hosted invoice PDF, for a
    driver that supplies none. Publish it (`php artisan vendor:publish --tag=billing-views`) to
    override the layout. All amounts are pre-formatted by the renderer; this template is presentation only.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('billing::invoice.title', ['number' => $number]) }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1a1a1a; font-size: 12px; line-height: 1.5; margin: 40px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .parties { width: 100%; margin: 24px 0; }
        .parties td { vertical-align: top; width: 50%; }
        .muted { color: #666; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.lines th, table.lines td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.totals { width: 40%; margin-left: auto; margin-top: 16px; border-collapse: collapse; }
        table.totals td { padding: 4px 8px; }
        table.totals td.num { text-align: right; }
        table.totals tr.total td { border-top: 2px solid #1a1a1a; font-weight: bold; }
        .note { margin-top: 24px; }
    </style>
</head>
<body>
    <h1>{{ $isCorrection ? __('billing::invoice.correction') : __('billing::invoice.invoice') }}</h1>
    <p class="muted">
        {{ __('billing::invoice.number', ['number' => $number]) }}
        @if ($issuedAt)
            &middot; {{ __('billing::invoice.issued', ['date' => $issuedAt->format('d.m.Y')]) }}
        @endif
    </p>

    <table class="parties">
        <tr>
            <td>
                <strong>{{ __('billing::invoice.from') }}</strong><br>
                {{ $seller['name'] ?? '' }}<br>
                {{ $seller['address'] ?? '' }}<br>
                {{ trim(($seller['postcode'] ?? '').' '.($seller['city'] ?? '')) }}<br>
                {{ $seller['country'] ?? '' }}
                @if (! empty($seller['vat_id']))
                    <br>{{ __('billing::invoice.vat_id', ['id' => $seller['vat_id']]) }}
                @endif
            </td>
            <td>
                <strong>{{ __('billing::invoice.to') }}</strong><br>
                {{ $buyer['name'] ?? '' }}<br>
                {{ $buyer['address'] ?? '' }}<br>
                {{ trim(($buyer['postcode'] ?? '').' '.($buyer['city'] ?? '')) }}<br>
                {{ $buyer['country'] ?? '' }}
                @if (! empty($buyer['vat_id']))
                    <br>{{ __('billing::invoice.vat_id', ['id' => $buyer['vat_id']]) }}
                @endif
            </td>
        </tr>
    </table>

    @if (! empty($lines))
        <table class="lines">
            <thead>
                <tr>
                    <th>{{ __('billing::invoice.description') }}</th>
                    <th class="num">{{ __('billing::invoice.quantity') }}</th>
                    <th class="num">{{ __('billing::invoice.unit_price') }}</th>
                    <th class="num">{{ __('billing::invoice.vat_rate') }}</th>
                    <th class="num">{{ __('billing::invoice.net') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['description'] }}</td>
                        <td class="num">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $line['unitPrice'] }}</td>
                        <td class="num">{{ $line['rate'] }}</td>
                        <td class="num">{{ $line['net'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr>
            <td>{{ __('billing::invoice.subtotal') }}</td>
            <td class="num">{{ $subtotal }}</td>
        </tr>
        <tr>
            <td>{{ $reverseCharge ? __('billing::invoice.vat_reverse_charge') : __('billing::invoice.vat') }}</td>
            <td class="num">{{ $tax }}</td>
        </tr>
        <tr class="total">
            <td>{{ __('billing::invoice.total') }}</td>
            <td class="num">{{ $total }}</td>
        </tr>
    </table>

    @if ($reverseCharge || $vatNote)
        <p class="note muted">{{ $vatNote ?? __('billing::invoice.reverse_charge_note') }}</p>
    @endif
</body>
</html>
