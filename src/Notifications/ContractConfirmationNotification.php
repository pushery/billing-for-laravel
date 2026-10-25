<?php

declare(strict_types=1);

namespace Pushery\Billing\Notifications;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Pushery\Billing\Enums\BillingAction;
use Pushery\Billing\Enums\ContractKind;
use Pushery\Billing\Support\CatalogLabel;
use Pushery\Billing\Support\LocalizedDate;
use Pushery\Billing\ValueObjects\ContractConfirmation;
use Pushery\Billing\ValueObjects\WithdrawalConsent;

/**
 * The confirmation of a contract, sent as a subscription begins or an add-on is bought, before the service does.
 *
 * It states what the package knows, what was bought and the day, repeats the declarations the buyer made in the words
 * they were shown, and renders the operator's view below the button: the trader's identity and address, the price and
 * the terms, the withdrawal notice with the model withdrawal form. The mail is Markdown, so the view writes Markdown,
 * and HTML where it needs to.
 */
final class ContractConfirmationNotification extends BillingNotification
{
    public function __construct(private readonly ContractConfirmation $contract) {}

    public function toMail(object $notifiable): MailMessage
    {
        $date = LocalizedDate::long($this->contract->startedAt);
        $label = $this->contract->productLabel === null ? null : CatalogLabel::translate($this->contract->productLabel);
        $purchase = $this->contract->kind === ContractKind::Purchase;
        $mail = new MailMessage()
            ->subject(Lang::get('billing::notifications.contract_confirmation.subject'))
            ->line(match (true) {
                $purchase => Lang::get('billing::notifications.contract_confirmation.intro_purchase', ['addon' => $label ?? (string) $this->contract->productKey, 'date' => $date]),
                $label === null => Lang::get('billing::notifications.contract_confirmation.intro_untitled', ['date' => $date]),
                default => Lang::get('billing::notifications.contract_confirmation.intro', ['tier' => $label, 'date' => $date]),
            });

        // Each declaration in its wording, and only the ones the buyer made. A subscription asks for the request that
        // the service begin early and seldom for more, so one of the two is the ordinary case here, unlike on a
        // receipt for a work whose right ends on delivery.
        $declared = $this->declared($this->contract->consent);

        if ($declared !== []) {
            $mail->line(Lang::get('billing::notifications.contract_confirmation.declarations'));

            foreach ($declared as $wording) {
                $mail->line($wording);
            }
        }

        // A subscription is managed on the plan screen; a purchase leaves a receipt, and that is where it is found again.
        $mail = $purchase
            ? $this->withAction($mail, Lang::get('billing::notifications.contract_confirmation.cta_purchase'), $this->actionFor($notifiable, BillingAction::Invoices))
            : $this->withAction($mail, Lang::get('billing::notifications.contract_confirmation.cta'), $this->actionFor($notifiable, BillingAction::Subscription));

        /** @var view-string $view the operator's view, named in `billing.consumer_rights.contract_confirmation_view` */
        $view = $this->contract->view;

        // Below the button: the operator's part is the long one, and the reader who wants the plan should not have to
        // scroll past a withdrawal notice to reach it.
        return $mail->line(new HtmlString(Container::getInstance()->make(Factory::class)->make($view, [
            'owner' => $notifiable,
            'contract' => $this->contract,
        ])->render()));
    }

    /** @return array<string, string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'contract_confirmation',
            'kind' => $this->contract->kind->value,
            'product' => $this->contract->productKey,
            'started_at' => $this->contract->startedAt->format('Y-m-d'),
        ];
    }

    /**
     * The wording of each declaration the buyer made, in the order the law names them.
     *
     * @return list<string>
     */
    private function declared(?WithdrawalConsent $consent): array
    {
        if (! $consent instanceof WithdrawalConsent) {
            return [];
        }

        $wordings = [];

        if ($consent->consentedToImmediateProvision && $consent->immediateProvisionNotice !== null) {
            $wordings[] = $consent->immediateProvisionNotice;
        }

        if ($consent->acknowledgedForfeiture && $consent->forfeitureNotice !== null) {
            $wordings[] = $consent->forfeitureNotice;
        }

        return $wordings;
    }
}
