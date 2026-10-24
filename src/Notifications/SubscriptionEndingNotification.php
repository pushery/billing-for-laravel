<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Pushery\Billing\Contracts\TierCatalog;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\ValueObjects\MerchantScope;

/**
 * The reminder sent as a canceled subscription nears its end. The mail names the day in the reader's language
 * (`LocalizedDate::long()`), the database entry carries it as an ISO date, and both read the day in the zone of the
 * moment passed in, so the two never name different days.
 *
 * A subscription held with a merchant is named after the merchant where the name is known, and its mail has no
 * button: the account hub's subscription screen shows the platform's own subscription, and a button there would
 * resume a different one.
 *
 * The platform's own subscription ends in the zero tier, where every owner without one stands, and the mail says
 * so where the installation names one: the reader learns what the end means, not only when it comes.
 */
final class SubscriptionEndingNotification extends BillingNotification
{
    public function __construct(
        private readonly DateTimeInterface $endsAt,
        private readonly ?MerchantScope $merchant = null,
        private readonly ?string $merchantName = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $date = LocalizedDate::long($this->endsAt);
        $intro = Lang::get('billing::notifications.subscription_ending.intro', ['date' => $date]);
        $action = null;

        if ($this->merchantName !== null) {
            $intro = Lang::get('billing::notifications.subscription_ending.intro_merchant', ['date' => $date, 'merchant' => $this->merchantName]);
        }

        if (! $this->heldWithAMerchant()) {
            $action = $this->actionFor($notifiable, BillingAction::Subscription);
        }

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.subscription_ending.subject'))
            ->line($intro)
            ->line(Lang::get('billing::notifications.subscription_ending.outro'));

        // A subscription held with a merchant ends in no tier of the platform's, so its mail names none.
        $tier = $this->heldWithAMerchant() ? null : $this->tierAfterTheEnd();

        if ($tier !== null) {
            $mail->line(Lang::get('billing::notifications.subscription_ending.tier_after', ['tier' => $tier]));
        }

        return $this->withAction($mail, Lang::get('billing::notifications.subscription_ending.cta'), $action);
    }

    /** @return array<string, string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_ending',
            'ends_at' => $this->endsAt->format('Y-m-d'),
            'merchant' => $this->heldWithAMerchant() ? $this->merchant?->uid() : null,
        ];
    }

    private function heldWithAMerchant(): bool
    {
        return $this->merchant instanceof MerchantScope && ! $this->merchant->isPlatform();
    }

    /**
     * The label of the zero tier, or null where the installation names none or no tier carries that key.
     *
     * Read as the mail is written, like a tier's label everywhere else, so a label renamed since the cancellation
     * is the one the reader knows now.
     */
    private function tierAfterTheEnd(): ?string
    {
        $zero = Container::getInstance()->make(Repository::class)->get('billing.zero_tier');

        if (! is_string($zero) || $zero === '') {
            return null;
        }

        return Container::getInstance()->make(TierCatalog::class)->find($zero)?->label;
    }
}
