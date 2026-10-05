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
use Pushery\Billing\Support\UniqueRow;
use RuntimeException;

/**
 * Keeps each answer the provider reports about a buyer's tax ID, and tells the application when the answer is no.
 *
 * An answer is kept whenever it differs from the one kept last for the tax ID, because when a verdict became known
 * is part of the record. A verdict can come back after a different one: a register that confirms a number again
 * after rejecting it gives an answer of its own, and the reverse charge rests on it again. The same answer reported
 * twice in a row is kept once.
 *
 * Only a number the register does not know raises `TaxIdVerificationFailed`, and only when the register's deciding
 * answer before it was something else, so the application hears of a rejection once. A pending or unavailable answer
 * decides nothing, a verified one confirms what the sale already assumed, and a removal is the customer's act rather
 * than a register's verdict, which leaves the sales made under the number as they were.
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

        $last = $this->lastAnswer($event);

        if ($last instanceof TaxIdVerification && $last->status === $event->status) {
            return; // the answer kept last already says this
        }

        $answer = UniqueRow::firstOrCreate(
            TaxIdVerification::model()::query(),
            [
                'provider' => $event->provider,
                'tax_id_reference' => $event->taxIdReference,
                'status' => $event->status,
                'follows' => $last instanceof TaxIdVerification ? $last->id : 0,
            ],
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

        if (! $answer->wasRecentlyCreated || $event->status !== TaxIdVerificationStatus::Unverified) {
            return;
        }

        if ($this->decidingAnswerBefore($answer)?->status === TaxIdVerificationStatus::Unverified) {
            return; // the application heard of this rejection when the register gave it
        }

        $this->events->dispatch(new TaxIdVerificationFailed(
            $owner,
            $event->type,
            $event->value,
            $this->reverseChargeInvoices($owner, $event),
        ));
    }

    /**
     * Once per tax ID and change of answer. The provider reports the same answer again when something else about the
     * tax ID changes, and that repeat names the same answer before it as the one kept, so nothing runs twice. The
     * same verdict after a different one names that different one, and runs.
     */
    public function dedupReference(BillingDomainEvent $event): string
    {
        if (! $event instanceof TaxIdVerificationReported) {
            throw new RuntimeException('RecordTaxIdVerification only handles TaxIdVerificationReported events.');
        }

        $last = $this->lastAnswer($event);

        if ($last instanceof TaxIdVerification && $last->status === $event->status) {
            return $event->taxIdReference.':'.$event->status->value.':'.$last->follows;
        }

        return $event->taxIdReference.':'.$event->status->value.':'.($last instanceof TaxIdVerification ? $last->id : 0);
    }

    /**
     * The answer kept last for this tax ID, in the order the reverse charge reads them.
     */
    private function lastAnswer(TaxIdVerificationReported $event): ?TaxIdVerification
    {
        return TaxIdVerification::model()::query()
            ->where('provider', $event->provider)
            ->where('tax_id_reference', $event->taxIdReference)
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The latest deciding answer this tax ID had before the given one.
     */
    private function decidingAnswerBefore(TaxIdVerification $answer): ?TaxIdVerification
    {
        $earlier = TaxIdVerification::model()::query()
            ->where('provider', $answer->provider)
            ->where('tax_id_reference', $answer->tax_id_reference)
            ->whereKeyNot($answer->getKey())
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get();

        foreach ($earlier as $candidate) {
            if ($candidate->status->decides()) {
                return $candidate;
            }
        }

        return null;
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

            // A provider's invoice is stored with the provider's id, so the check on `provider_id` removes no
            // invoice here. It narrows the type to the list of strings this returns.
            if (is_string($vatId) && $this->normalized($vatId) === $wanted && is_string($invoice->provider_id)) {
                $references[] = $invoice->provider_id;
            }
        }

        return $references;
    }

    private function normalized(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $value));
    }
}
