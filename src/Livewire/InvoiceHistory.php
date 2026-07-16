<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\ValueObjects\InvoicePage;

/**
 * The account-hub invoice-history screen. It lists the owner's recent invoices as neutral DTOs; the
 * document itself streams from a dedicated download route (see BillingController::downloadInvoice), which
 * the driver owner-checks before returning anything, so one owner can never pull another's document.
 */
final class InvoiceHistory extends AccountScreen
{
    use DegradesGracefully;

    /** The provider never returns more than this many in one page (a driver-side ceiling on the read). */
    private const int PROVIDER_CAP = 100;

    /** How many invoices to show; widened by "load older" up to the provider's hard cap. */
    public int $perPage = 24;

    public function render(): View
    {
        return $this->view('billing::livewire.invoice-history', [
            // Listing invoices is a provider read; degrade to a notice rather than 500 the whole screen.
            'page' => $this->orDegrade(
                fn (): InvoicePage => app(Invoices::class)->recent($this->owner(), $this->perPage),
                new InvoicePage([]),
            ),
        ]);
    }

    /** Widen the page to pull older invoices, up to the provider's hard cap. Shown only while hasMore. */
    public function loadOlder(): void
    {
        $this->perPage = min($this->perPage + 24, self::PROVIDER_CAP);
    }
}
