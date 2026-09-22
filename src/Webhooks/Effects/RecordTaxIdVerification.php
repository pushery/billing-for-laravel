<?php

declare(strict_types=1);

namespace Pushery\Billing\Webhooks\Effects;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Pushery\Billing\Contracts\CustomerDirectory;
use Pushery\Billing\Contracts\DedupesOnReference;
use Pushery\Billing\Enums\TaxIdVerificationStatus;
use Pushery\Billing\Events\BillingDomainEvent;
use Pushery\Billing\Events\TaxIdVerificationFailed;
use Pushery\Billing\Events\TaxIdVerificationReported;
use Pushery\Billing\Models\InvoiceRecord;
use Pushery\Billing\Models\TaxIdVerification;
use RuntimeException;

/**
 * Keeps each answer the provider reports about a buyer's tax ID, and tells the application when the answer is no.
 *
 * The answer is kept for every status, because when a verdict became known is part of the record. Only a number the
 * register does not know raises `TaxIdVerificationFailed`: a pending or unavailable answer decides nothing, and a
 * verified one confirms what the sale already assumed.
 */
final readonly class RecordTaxIdVerification implements DedupesOnReference
{
    public function __construct(
        private CustomerDirectory $directory,
        private Dispatcher $events,
    ) {}

    public function __invoke(TaxIdVerificationReported $event): void
    {
        $owner = $this->directory->ownerForReference($event->customerReference);

        if (! $owner instanceof Model) {
            return; // a customer this app does not own
        }

        TaxIdVerification::model()::query()->firstOrCreate(
            ['provider' => $event->provider, 'tax_id_reference' => $event->taxIdReference, 'status' => $event->status],
            [
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'customer_reference' => $event->customerReference,
                'type' => $event->type,
                'value' => $event->value,
                'verified_name' => $event->verifiedName,
                'verified_address' => $event->verifiedAddress,
                'reported_at' => Carbon::now(),
            ],
        );

        if ($event->status !== TaxIdVerificationStatus::Unverified) {
            return;
        }

        $this->events->dispatch(new TaxIdVerificationFailed(
            $owner,
            $event->type,
            $event->value,
            $this->reverseChargeInvoices($owner, $event),
        ));
    }

    /**
     * Once per tax ID and answer. The provider can report the same answer again when something else about the tax
     * ID changes, and a second failure for an answer already acted on would send the application over the same
     * invoices twice.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof TaxIdVerificationReported) {
            throw new RuntimeException('RecordTaxIdVerification only handles TaxIdVerificationReported events.');
        }

        return $event->taxIdReference.':'.$event->status->value;
    }

    /**
     * The provider references of this owner's invoices issued with the charge reversed under this number.
     *
     * Compared without spaces and without regard to case, because the same number can be written either way.
     *
     * @return list<string>
     */
    private function reverseChargeInvoices(Model $owner, TaxIdVerificationReported $event): array
    {
        $wanted = $this->normalized($event->value);
        $references = [];

        $invoices = InvoiceRecord::model()::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('provider', $event->provider)
            ->where('reverse_charge', true)
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $vatId = $invoice->buyer['vat_id'] ?? null;

            // The string check on `provider_id` is EQUIVALENT under mutation: a provider's invoice is stored with the
            // provider's id. Static analysis needs it for the list of strings this returns (measured 2026-09-14).
            if (is_string($vatId) && $this->normalized($vatId) === $wanted && is_string($invoice->provider_id)) {
                $references[] = $invoice->provider_id;
            }
        }

        return $references;
    }

    private function normalized(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
