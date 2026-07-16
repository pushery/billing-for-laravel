<?php

declare(strict_types=1);

namespace Pushery\Billing\Livewire;

use Illuminate\Contracts\View\View;
use Pushery\Billing\Contracts\Invoices;
use Pushery\Billing\Livewire\Concerns\DegradesGracefully;
use Pushery\Billing\ValueObjects\InvoicePage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The account-hub invoice-history screen. It lists the owner's recent invoices as neutral DTOs and
 * streams a single invoice's rendered document on request — but only after the driver confirms the
 * invoice belongs to the owner (download returns null otherwise), so one owner can never pull
 * another's document.
 */
final class InvoiceHistory extends AccountScreen
{
    use DegradesGracefully;

    public function render(): View
    {
        return $this->view('billing::livewire.invoice-history', [
            // Listing invoices is a provider read; degrade to a notice rather than 500 the whole screen.
            'page' => $this->orDegrade(fn (): InvoicePage => app(Invoices::class)->recent($this->owner()), new InvoicePage([])),
        ]);
    }

    public function download(string $invoiceId): StreamedResponse
    {
        $document = app(Invoices::class)->download($this->owner(), $invoiceId);

        abort_if($document === null, 404);

        return response()->streamDownload(
            function () use ($document): void {
                echo $document->contents;
            },
            $document->filename,
            ['Content-Type' => $document->mimeType],
        );
    }
}
