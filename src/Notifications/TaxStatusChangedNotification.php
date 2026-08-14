<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Enums\CreatorTaxStatus;

/**
 * A merchant's tax standing changed on its own, and they are told.
 *
 * The change is not a preference and neither is this notice — which is why it is non-suppressible. Crossing
 * a turnover limit changes what the merchant owes and what their own documents have to say, from a date the
 * platform picked out of its own records. A merchant who is not told simply keeps filing as they were, and
 * finds out from an authority.
 *
 * Three things are stated because a merchant cannot act on fewer: WHAT changed, FROM WHEN, and what it means
 * for the money they receive. The last one is the part that makes the notice usable rather than alarming —
 * the amount that arrives goes up because tax now travels with it, and without that sentence the first
 * larger settlement looks like an error.
 */
final class TaxStatusChangedNotification extends BillingNotification
{
    public function __construct(
        private readonly CreatorTaxStatus $previous,
        private readonly CreatorTaxStatus $current,
        private readonly CarbonInterface $effectiveFrom,
    ) {}

    /**
     * The one billing notice that deliberately carries NO call to action.
     *
     * Every other one points at a screen where the reader can do the thing it asks for. This one asks for
     * nothing: it tells a merchant that the tax standing recorded for them has changed and from when, which
     * is a statement about a decision somebody else already made. The hub has no screen that edits it —
     * standing is written by the status ledger, from a registry check or a declaration — so a button here
     * could only lead somewhere that does not answer the mail.
     *
     * A link that lands on the wrong screen is worse than none: it reads as "this is where you fix it", and
     * the reader arrives, finds nothing to change, and concludes the notice was noise.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return new MailMessage()
            ->subject(Lang::get('billing::notifications.tax_status_changed.subject'))
            ->line(Lang::get('billing::notifications.tax_status_changed.intro', [
                'from' => $this->previous->value,
                'to' => $this->current->value,
            ]))
            ->line(Lang::get('billing::notifications.tax_status_changed.effective', [
                'date' => $this->effectiveFrom->toDateString(),
            ]))
            ->line(Lang::get('billing::notifications.tax_status_changed.consequence'))
            ->line(Lang::get('billing::notifications.tax_status_changed.outro'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'tax_status_changed',
            'previous' => $this->previous->value,
            'current' => $this->current->value,
            'effective_from' => $this->effectiveFrom->toDateString(),
        ];
    }
}
