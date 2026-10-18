<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Override;
use Pushery\Billing\Consumer\DeclarationStatement;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Support\MailText;

/**
 * The confirmation of a cancellation declared through the cancellation button: its content, the date and time it
 * arrived and when the contract is to end, in text form and at once.
 *
 * The row is the receipt, not this message: `declared_at` was stamped before anything was queued, and a mail server
 * that never accepts the mail does not undo it. The notice carries the id and reads the row when it is sent, so it
 * says what was done with the declaration by then. It is written from {@see DeclarationStatement}, so it says what a
 * receipt page says, and every value the person typed reaches the mail through {@see MailText}, as typed and unable
 * to become a link.
 */
final class CancellationReceivedNotification extends BillingNotification
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
        $declaration = CancellationDeclaration::model()::query()->findOrFail($this->declarationId);

        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.cancellation_received.subject'))
            ->line(Lang::get('billing::notifications.cancellation_received.intro'))
            ->line(MailText::line(':sentence', ['sentence' => DeclarationStatement::cancellationDeclaredThrough()]));

        foreach (DeclarationStatement::cancellationLines($declaration) as $line) {
            $mail->line(MailText::line(':label: :value', $line));
        }

        return $this->withAction(
            $mail->line(Lang::get('billing::notifications.cancellation_received.outro')),
            Lang::get('billing::notifications.cancellation_received.cta'),
            DeclarationStatement::receiptUrl($declaration->token),
        );
    }

    /** @return array<string, string|int> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'cancellation_received',
            'declaration_id' => $this->declarationId,
        ];
    }
}
