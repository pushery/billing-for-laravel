<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Notification;
use Pushery\Billing\Enums\DeclarationStatus;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Pushery\Billing\Notifications\DeclarationAwaitingReviewNotification;

/**
 * Tells the operator about a declaration that waits for a person.
 *
 * A cancellation or a withdrawal the package does not carry out stays with `DeclarationStatus::AwaitingReview`, and a
 * declaration nobody sees leaves a contract running that the person has validly ended. So the declaration goes to the
 * review address the operator named, `billing.consumer_rights.declaration_review_address`, the moment it is clear
 * that it waits. Without an address nothing is sent, which is how the package behaved before.
 */
final readonly class DeclarationReview
{
    public static function announce(CancellationDeclaration|WithdrawalDeclaration $declaration): void
    {
        if ($declaration->status !== DeclarationStatus::AwaitingReview) {
            return;
        }

        $address = Container::getInstance()->make(Repository::class)->get('billing.consumer_rights.declaration_review_address');

        if (! is_string($address) || filter_var(trim($address), FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Notification::route('mail', trim($address))->notify(new DeclarationAwaitingReviewNotification(
            $declaration instanceof CancellationDeclaration ? 'cancellation' : 'withdrawal',
            $declaration->id,
        ));
    }
}
