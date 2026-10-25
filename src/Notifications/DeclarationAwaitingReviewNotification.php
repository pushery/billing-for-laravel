<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Override;
use Pushery\Billing\Consumer\DeclarationStatement;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Pushery\Billing\Support\MailText;

/**
 * The operator's notice of a declaration that waits for a person: why it waits, and the declaration line by line.
 *
 * It answers to the person who made the declaration, so a reply reaches them. Written in the application's language,
 * not the declaration's: it is read at the business. It reads the row when it is sent, like the confirmation the
 * person gets, so it says what the row says by then. The button goes to the screen that lists such declarations:
 * the route `billing.consumer_rights.declaration_review_route` names, or the admin console; without either the mail
 * has none.
 */
final class DeclarationAwaitingReviewNotification extends BillingNotification
{
    /** The subject and the opening line of the notice, by the kind of declaration it is about. */
    private const array WORDING = [
        'cancellation' => [
            'subject' => 'billing::notifications.declaration_awaiting_review.subject_cancellation',
            'intro' => 'billing::notifications.declaration_awaiting_review.intro_cancellation',
        ],
        'withdrawal' => [
            'subject' => 'billing::notifications.declaration_awaiting_review.subject_withdrawal',
            'intro' => 'billing::notifications.declaration_awaiting_review.intro_withdrawal',
        ],
    ];

    /**
     * @param  'cancellation'|'withdrawal'  $kind
     */
    public function __construct(
        private readonly string $kind,
        private readonly int $declarationId,
    ) {}

    /**
     * The notice goes to the review address, by mail alone: there is no owner row to write a database entry to.
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
        $declaration = $this->kind === 'cancellation'
            ? CancellationDeclaration::model()::query()->findOrFail($this->declarationId)
            : WithdrawalDeclaration::model()::query()->findOrFail($this->declarationId);

        $mail = new MailMessage()
            ->subject(Lang::get(self::WORDING[$this->kind]['subject']))
            ->replyTo($declaration->confirmation_email, $declaration->name)
            ->line(Lang::get(self::WORDING[$this->kind]['intro']))
            ->line($this->reason($declaration));

        $lines = $declaration instanceof CancellationDeclaration
            ? DeclarationStatement::cancellationLines($declaration)
            : DeclarationStatement::withdrawalLines($declaration);

        foreach ($lines as $line) {
            $mail->line(MailText::line(':label: :value', $line));
        }

        return $this->withAction($mail, Lang::get('billing::notifications.declaration_awaiting_review.cta'), $this->reviewUrl());
    }

    /** @return array<string, string|int> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'declaration_awaiting_review',
            'kind' => $this->kind,
            'declaration_id' => $this->declarationId,
        ];
    }

    /** The page that lists the declarations waiting for a person, or null where the application has none. */
    private function reviewUrl(): ?string
    {
        $route = Container::getInstance()->make(Repository::class)->get('billing.consumer_rights.declaration_review_route');

        foreach ([is_string($route) ? trim($route) : '', 'billing.admin.console'] as $name) {
            if ($name !== '' && Route::has($name)) {
                return URL::route($name);
            }
        }

        return null;
    }

    /**
     * Why the declaration waits: a failure the row names, a declaration made without signing in, a contract the
     * person does not hold, or, for a cancellation, a kind or a date a person has to judge.
     */
    private function reason(CancellationDeclaration|WithdrawalDeclaration $declaration): string
    {
        return match (true) {
            $declaration->failure !== null => Lang::get('billing::notifications.declaration_awaiting_review.reason_failure', ['failure' => $declaration->failure]),
            $declaration->owner_type === null => Lang::get('billing::notifications.declaration_awaiting_review.reason_signed_out'),
            $declaration->subscription_id === null => Lang::get('billing::notifications.declaration_awaiting_review.reason_unmatched'),
            default => Lang::get('billing::notifications.declaration_awaiting_review.reason_judgment'),
        };
    }
}
