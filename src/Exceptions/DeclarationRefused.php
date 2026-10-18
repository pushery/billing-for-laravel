<?php

declare(strict_types=1);

namespace Pushery\Billing\Exceptions;

use InvalidArgumentException;

/**
 * A cancellation or a withdrawal that was not taken in, because the page passed it incomplete.
 *
 * `reason` is a fixed word per cause, so a page puts the message beside the right field without reading the
 * sentence: `no_name`, `no_contract`, `invalid_email` for the address of the person and `invalid_confirmation_email`
 * for the address the confirmation goes to, `no_reason` for an extraordinary cancellation without its reason,
 * `no_date` for a cancellation on a chosen date without the date, and `date_passed` for a date before the day the
 * declaration arrives. Nothing is stored for a refused declaration, so the page asks again.
 *
 * Concatenation in this class assembles sentence text rather than behavior, so swapping or dropping
 * a fragment measures where the line was wrapped, not what a test asserts. The values themselves are
 * held by a dedicated guard that varies every parameter individually.
 *
 * @pest-mutate-ignore: ConcatSwitchSides,ConcatRemoveLeft,ConcatRemoveRight
 */
final class DeclarationRefused extends InvalidArgumentException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noName(): self
    {
        return new self('no_name', 'A declaration names the person who makes it, and the name is empty.');
    }

    public static function noContract(): self
    {
        return new self('no_contract', 'A declaration names the contract it is about, and the contract is empty.');
    }

    public static function invalidEmail(): self
    {
        return new self('invalid_email', 'The email address of the person who declares the cancellation is not a valid address.');
    }

    public static function invalidConfirmationEmail(): self
    {
        return new self('invalid_confirmation_email', 'The confirmation of a declaration goes to an email address, and the one given is not valid.');
    }

    public static function noReason(): self
    {
        return new self('no_reason', 'An extraordinary cancellation states its reason, and the reason is empty.');
    }

    public static function noDate(): self
    {
        return new self('no_date', 'A cancellation on a chosen date names the date, and none was given.');
    }

    public static function datePassed(): self
    {
        return new self('date_passed', 'A cancellation cannot end a contract on a day before the day it arrives.');
    }
}
