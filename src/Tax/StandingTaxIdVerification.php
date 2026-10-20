<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Pushery\Billing\Enums\TaxIdVerificationStatus;
use Pushery\Billing\Models\TaxIdVerification;

/**
 * The verified tax id a business treatment of a buyer may rest on, if one stands.
 *
 * A business treatment changes who owes the tax: the reverse charge moves it to the buyer, and a sale of goods to
 * a business is no sale a platform is treated as supplying. So it never rests on an id that was merely present.
 * The one dated, recorded fact that clears that bar is an answer a register gave for the id.
 *
 * Read across providers on purpose. The row records which provider asked, but the answer is about the buyer and
 * the register: an id the register confirmed is confirmed however the question reached it.
 */
final readonly class StandingTaxIdVerification
{
    /**
     * Of an owner's tax ids, the most recent whose latest deciding answer is `verified`.
     *
     * An answer decides when it is `verified`, `unverified` or `removed`. A `pending` or `unavailable` one says the
     * register was not heard, so it neither confirms an id nor takes a confirmation back. An id that turns
     * `verified` again after an `unverified` answer has a row for that answer too, and the treatment rests on it
     * again.
     */
    public function of(string $ownerType, int|string $ownerId): ?TaxIdVerification
    {
        $answers = TaxIdVerification::model()::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereIn('status', array_values(array_filter(
                TaxIdVerificationStatus::cases(),
                static fn (TaxIdVerificationStatus $status): bool => $status->decides(),
            )))
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->get();

        $decided = [];

        foreach ($answers as $answer) {
            $id = $answer->provider.'|'.$answer->tax_id_reference;

            if (isset($decided[$id])) {
                continue;
            }

            $decided[$id] = true;

            if ($answer->status === TaxIdVerificationStatus::Verified) {
                return $answer;
            }
        }

        return null;
    }
}
