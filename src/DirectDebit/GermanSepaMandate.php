<?php

declare(strict_types=1);

namespace Pushery\Billing\DirectDebit;

use Pushery\Billing\Contracts\SepaMandateWording;
use Pushery\Billing\ValueObjects\SepaCreditor;
use Pushery\Billing\ValueObjects\SepaMandateText;

/**
 * The German SEPA direct debit mandate: the standard form of the German banking industry for a recurring core
 * direct debit, in the first person of the account holder.
 *
 * The notice period is agreed in a sentence of its own beside the mandate, because the rulebook keeps the mandate
 * free of any other text. It addresses the account holder as `du`, as every text of the package does.
 */
final readonly class GermanSepaMandate implements SepaMandateWording
{
    /**
     * The days a debit is announced before it is drawn when nothing else is agreed.
     *
     * The agreement sentence names this default only where the configured period differs from it.
     */
    private const int RULEBOOK_NOTICE_DAYS = 14;

    /**
     * Country names the payee's address may end in, in German.
     *
     * @var array<string, string>
     */
    private const array COUNTRIES = [
        'AT' => 'Österreich', 'BE' => 'Belgien', 'DE' => 'Deutschland', 'FR' => 'Frankreich',
        'IE' => 'Irland', 'LU' => 'Luxemburg', 'NL' => 'Niederlande',
    ];

    public function text(SepaCreditor $creditor, string $onBehalfOf, string $reference, int $noticeDays): SepaMandateText
    {
        $payee = sprintf('%s, %s, %s', $creditor->name, $creditor->address, self::COUNTRIES[$creditor->country] ?? $creditor->country);

        return new SepaMandateText(
            language: 'de',
            reference: $reference,
            heading: 'SEPA-Lastschriftmandat',
            particulars: [
                ['label' => 'Zahlungsempfänger', 'value' => "{$payee}, im Auftrag von {$onBehalfOf}"],
                ['label' => 'Gläubiger-Identifikationsnummer', 'value' => $creditor->identifier],
                ['label' => 'Mandatsreferenz', 'value' => $reference],
                ['label' => 'Zahlungsart', 'value' => 'Wiederkehrende Zahlung'],
            ],
            paragraphs: [
                "Ich ermächtige die {$creditor->name}, im Auftrag von {$onBehalfOf} Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von der {$creditor->name} auf mein Konto gezogenen Lastschriften einzulösen.",
                'Hinweis: Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen.',
            ],
            agreement: $this->agreement($onBehalfOf, $noticeDays),
            noticeDays: $noticeDays,
        );
    }

    /** The notice period, agreed next to the mandate. */
    private function agreement(string $onBehalfOf, int $noticeDays): string
    {
        $days = $noticeDays === 1 ? 'einen Kalendertag' : "{$noticeDays} Kalendertage";
        $sentence = "Vorabankündigung: {$onBehalfOf} kündigt jede Lastschrift spätestens {$days} vor ihrer Fälligkeit per E-Mail an.";

        if ($noticeDays === self::RULEBOOK_NOTICE_DAYS) {
            return $sentence;
        }

        return $sentence.' Mit der Erteilung des Mandats vereinbarst du diese Frist anstelle der '.self::RULEBOOK_NOTICE_DAYS.' Kalendertage, die das SEPA-Regelwerk sonst vorsieht.';
    }
}
