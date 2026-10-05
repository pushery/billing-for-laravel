{{-- An invoice's status as a badge, shared by the table and by the cards that stand in for it on a phone. --}}
@php($intent = $status->badgeIntent())
<span @class([
    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200' => $intent === 'success',
    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200' => $intent === 'info',
    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $intent === 'warning',
    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $intent === 'danger',
    'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' => $intent === 'neutral',
])>
    {{ __('billing::account.invoice_status.'.$status->value) }}
</span>
