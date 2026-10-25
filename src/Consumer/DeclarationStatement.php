<?php

declare(strict_types=1);

namespace Pushery\Billing\Consumer;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pushery\Billing\Enums\CancellationKind;
use Pushery\Billing\Enums\CancellationTiming;
use Pushery\Billing\Models\CancellationDeclaration;
use Pushery\Billing\Models\WithdrawalDeclaration;
use Pushery\Billing\Support\LocalizedDate;

/**
 * What a cancellation or a withdrawal said, in the one wording the confirmation mail, a receipt page and a saved copy
 * all use.
 *
 * The law has the person keep what they declared and has the business confirm the same content in text form.
 * Written once for every place it is shown, the copies cannot drift apart, and the day somebody lays the mail
 * beside the saved page is the day it matters that they say the same thing. A page of your own renders the lines,
 * label and value each, under the sentence that says how the declaration was made.
 *
 * Every moment is stated in `billing.consumer_rights.declaration_timezone` with the zone named, so a receipt time
 * does not shift with whoever reads it. Labels come in the current language; values stay as the person entered them.
 */
final class DeclarationStatement
{
    /**
     * The cancellation line by line.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function cancellationLines(CancellationDeclaration $declaration): array
    {
        $lines = [
            self::line('received', self::moment($declaration->declared_at)),
            self::line('kind', self::text('billing::declarations.cancellation.kinds.'.$declaration->kind->value)),
        ];

        if ($declaration->kind === CancellationKind::Extraordinary) {
            $lines[] = self::line('reason', (string) $declaration->reason);
        }

        return [
            ...$lines,
            self::line('name', $declaration->name),
            self::line('email', $declaration->email),
            self::line('contract', $declaration->contract),
            self::line('ends', self::cancellationEnd($declaration)),
            self::line('confirmation_sent_to', $declaration->confirmation_email),
        ];
    }

    /**
     * The withdrawal line by line: when it arrived, who sent it, the contract, and where the confirmation went.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function withdrawalLines(WithdrawalDeclaration $declaration): array
    {
        return [
            self::line('received', self::moment($declaration->declared_at)),
            self::line('name', $declaration->name),
            self::line('contract', $declaration->contract),
            self::line('confirmation_sent_to', $declaration->confirmation_email),
        ];
    }

    /**
     * How a withdrawal was declared, quoting the confirmation button of the withdrawal function by its own label,
     * `billing::declarations.withdrawal.button`.
     */
    public static function withdrawalDeclaredThrough(): string
    {
        return self::text('billing::declarations.withdrawal.declared_through', [
            'button' => self::text('billing::declarations.withdrawal.button'),
            'app' => self::appName(),
        ]);
    }

    /**
     * How a cancellation was declared, quoting the confirmation button by its own label.
     *
     * A saved copy has to show that the cancellation was declared by pressing that button, so the label is quoted
     * rather than described. The label is `billing::declarations.cancellation.button`: a page that writes its button
     * from the same key cannot quote a different one.
     */
    public static function cancellationDeclaredThrough(): string
    {
        return self::text('billing::declarations.cancellation.declared_through', [
            'button' => self::text('billing::declarations.cancellation.button'),
            'app' => self::appName(),
        ]);
    }

    /**
     * When the contract ends, as declared and, once the business knows it, as a date.
     *
     * The confirmation names the time the contract is to end. For the earliest possible date that time is known once
     * the contract was ended, so it is stated rather than left for the reader to work out.
     */
    public static function cancellationEnd(CancellationDeclaration $declaration): string
    {
        if ($declaration->ends === CancellationTiming::OnDate && $declaration->ends_on instanceof DateTimeInterface) {
            return (string) LocalizedDate::long($declaration->ends_on);
        }

        if ($declaration->ends_at instanceof DateTimeInterface) {
            return self::text('billing::declarations.cancellation.ends.earliest_possible_on', [
                'date' => (string) LocalizedDate::long(CarbonImmutable::instance($declaration->ends_at)->setTimezone(self::zone())),
            ]);
        }

        return self::text('billing::declarations.cancellation.ends.earliest_possible');
    }

    /** A moment in a statement: the day, the time to the second, and the zone it is in. */
    public static function moment(DateTimeInterface $at): string
    {
        return LocalizedDate::longWithSeconds(CarbonImmutable::instance($at)->setTimezone(self::zone()));
    }

    /**
     * The zone every moment of a statement is given in: `billing.consumer_rights.declaration_timezone`, or UTC where
     * that names no zone, because a moment with its zone named is still one moment.
     */
    public static function zone(): DateTimeZone
    {
        $zone = Container::getInstance()->make(Repository::class)->get('billing.consumer_rights.declaration_timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true)
            ? new DateTimeZone($zone)
            : new DateTimeZone('UTC');
    }

    /**
     * The address of the receipt page for a declaration, or null where the application names none.
     *
     * `billing.consumer_rights.declaration_receipt_route` names a route of the application with one parameter
     * for the declaration's token. The token is passed by position, so it fills that parameter whatever the
     * route calls it: `{token}` is the name recorders that mask a secret in a path look for, and a route that
     * calls it `{declaration}` keeps working. The link opens the receipt for whoever holds it and does not
     * expire. The package draws no receipt page of its own.
     */
    public static function receiptUrl(string $token): ?string
    {
        $route = Container::getInstance()->make(Repository::class)->get('billing.consumer_rights.declaration_receipt_route');

        return is_string($route) && $route !== '' && Route::has($route) ? URL::route($route, [$token]) : null;
    }

    /** @return array{label: string, value: string} */
    private static function line(string $label, string $value): array
    {
        return ['label' => self::text('billing::declarations.labels.'.$label), 'value' => $value];
    }

    /** @param  array<string, string>  $replace */
    private static function text(string $key, array $replace = []): string
    {
        $text = Lang::get($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private static function appName(): string
    {
        $name = Container::getInstance()->make(Repository::class)->get('app.name');

        return is_string($name) ? $name : '';
    }
}
