<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use DateTimeInterface;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Override;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\Support\LocalizedMoney;
use Pushery\Billing\Support\MarkdownText;
use Pushery\Billing\ValueObjects\Money;

/**
 * The notice a payer gets before a SEPA direct debit is drawn: the amount, the day, and who collects it.
 *
 * The SEPA core rulebook lets a debit be drawn only once the payer was told, and the local engine sends this the
 * agreed number of days before. A notice for a subscription announces the amount for each later due date as well,
 * so the payer is told again only when the amount changes. The mandate reference and the account are named where
 * the mandate was given on the application's own form, which is where the package knows them.
 */
final class DirectDebitAnnouncedNotification extends BillingNotification
{
    public function __construct(
        private readonly Money $amount,
        private readonly DateTimeInterface $debitOn,
        private readonly string $payee,
        private readonly string $creditorIdentifier,
        /** Whether the amount is announced for each later due date of a subscription as well. */
        private readonly bool $recurring,
        /** The mandate reference, where the mandate was given on the application's form. */
        private readonly ?string $mandateReference = null,
        /** The last four characters of the account, where the mandate was given on the application's form. */
        private readonly ?string $accountLastFour = null,
    ) {}

    /**
     * A notice routed to the address the account holder gave goes by mail alone: the address is all there is to
     * deliver to, and a database channel has no owner row to write to.
     *
     * @return list<string>
     */
    #[Override]
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : parent::via($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.direct_debit_announced.subject'))
            ->line(Lang::get('billing::notifications.direct_debit_announced.intro', [
                'payee' => $this->payee,
                'amount' => LocalizedMoney::format($this->amount),
                'date' => LocalizedDate::long($this->debitOn),
            ]));

        if ($this->recurring) {
            $mail->line(Lang::get('billing::notifications.direct_debit_announced.recurring'));
        }

        // The facts of the debit as one block, a fact a line with its label in bold, rather than a
        // paragraph each: the account and the mandate where they are known, the creditor always.
        $facts = [];

        if ($this->accountLastFour !== null) {
            $facts[] = [
                'label' => Lang::get('billing::notifications.direct_debit_announced.account_label'),
                'value' => Lang::get('billing::notifications.direct_debit_announced.account_value', ['account' => $this->accountLastFour]),
            ];
        }

        if ($this->mandateReference !== null) {
            $facts[] = ['label' => Lang::get('billing::notifications.direct_debit_announced.mandate_label'), 'value' => $this->mandateReference];
        }

        $facts[] = ['label' => Lang::get('billing::notifications.direct_debit_announced.creditor_label'), 'value' => $this->creditorIdentifier];

        return $this->withAction(
            $mail->line(MarkdownText::pairs($facts))
                ->line(Lang::get('billing::notifications.direct_debit_announced.outro')),
            Lang::get('billing::notifications.direct_debit_announced.cta'),
            $this->actionFor($notifiable, BillingAction::PaymentMethods),
        );
    }

    /** @return array<string, string|bool|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'direct_debit_announced',
            'amount' => $this->amount->format(),
            'debit_on' => $this->debitOn->format('Y-m-d'),
            'recurring' => $this->recurring,
            'mandate_reference' => $this->mandateReference,
        ];
    }
}
