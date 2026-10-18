<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * What became of a declaration made through the cancellation button or the withdrawal function.
 *
 * The declaration takes effect when it arrives, whatever its status says. The status records the business's side
 * of it. `CarriedOut` means the package ended the contract itself the moment the declaration arrived, with the
 * refund it owed where one was due. `AwaitingReview` means a person has to deal with it, because something was left
 * to judge or the provider refused. `Resolved` means a person did.
 *
 * None of them moves the moment that counts: `declared_at` keeps when the declaration arrived.
 */
enum DeclarationStatus: string
{
    case AwaitingReview = 'awaiting_review';
    case CarriedOut = 'carried_out';
    case Resolved = 'resolved';
}
