<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Override;
use Pushery\Billing\Consumer\DeclarationStatement;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Pushery\Billing\Support\MailText;

/**
 * The confirmation of receipt for a withdrawal sent through the withdrawal function: its content and the date and
 * time it arrived, in text form and at once.
 *
 * It goes out before the withdrawal is carried out and does not wait for it, because the confirmation of receipt may
 * not depend on a payment provider being reachable. The notice carries the id and reads the row when it is sent, and
 * every value the person typed reaches the mail through {@see MailText}, as typed and unable to become a link.
 */
final class WithdrawalReceivedNotification extends BillingNotification
{
    public function __construct(private readonly int $declarationId) {}

    /**
     * The confirmation goes to the address the person gave, by mail alone: the address is all there is to deliver
     * to, and a database channel has no owner row to write to.
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
        $declaration = WithdrawalDeclaration::model()::query()->findOrFail($this->declarationId);

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.withdrawal_received.subject'))
            ->line(Lang::get('billing::notifications.withdrawal_received.intro'))
            ->line(MailText::line(':sentence', ['sentence' => DeclarationStatement::withdrawalDeclaredThrough()]));

        foreach (DeclarationStatement::withdrawalLines($declaration) as $line) {
            $mail->line(MailText::line(':label: :value', $line));
        }

        return $this->withAction(
            $mail->line(Lang::get('billing::notifications.withdrawal_received.outro')),
            Lang::get('billing::notifications.withdrawal_received.cta'),
            DeclarationStatement::receiptUrl($declaration->token),
        );
    }

    /** @return array<string, string|int> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'withdrawal_received',
            'declaration_id' => $this->declarationId,
        ];
    }
}
