<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Pushery\Billing\Contracts\SmallBusinessExemptionValidator;
use Pushery\Billing\Enums\VatIdValidation;
use Throwable;

/**
 * Asks the union's SME-on-the-web register whether a small-business exemption holds in a member state.
 *
 * The register is a SOAP service on a fixed EU host, described at
 * `https://ec.europa.eu/taxation_customs/sme-verification/smeVerificationService.wsdl` (read on 2026-09-23).
 * The request names the exemption number and the member state. The answer carries two booleans, and both
 * matter: `valid` says the number exists and is in force on the day of the request, and `exemptionGranted`,
 * listed per member state, says the exemption holds THERE. A number in force whose exemption is not granted
 * in the state asked about is no exemption in that state, so only both together confirm one.
 *
 * What comes back as a verdict and what does not, measured against the service on 2026-09-23:
 *
 * - A well-formed answer with `valid` false is a verdict, `Invalid`. The register lists no member state then.
 * - A fault naming the number is a verdict about the number the business supplied, `Invalid`: SOW-ERR-2600,
 *   the number is invalid, and SOW-ERR-2800, its format is invalid.
 * - A fault naming the question is about nobody's registration: SOW-ERR-11, a mandatory field is missing,
 *   SOW-ERR-2500, the member state code is invalid, and SOW-ERR-3000, the member state is the one the business
 *   is established in. It is thrown. Answered as either verdict it would hold every creator behind a question
 *   that was asked wrongly, and nothing on the record would say so.
 * - Everything else is `Unavailable`, because nobody answered: a timeout, an unreachable host, a fault the
 *   service raises about itself, an answer of a shape this class does not know.
 *
 * The number is sent as it was supplied, uppercased and without whitespace. The register documents its shape as
 * up to 22 letters or digits followed by `-EX` and decides that itself; a local pattern would be a second
 * opinion that can only be wrong. An empty number is not sent, because there is nothing to ask. Greece is `EL`
 * to the register and `GR` is refused, so `GR` is sent as `EL`.
 */
final class SmeOnTheWebExemptionValidator implements SmallBusinessExemptionValidator
{
    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/sme-verification/services/smeVerificationService';

    private const string ENVELOPE_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const string TYPES_NAMESPACE = 'urn:ec.europa.eu:taxud:sow:services:smeVerification:types';

    /** The faults that judge the number the business supplied. */
    private const array NUMBER_VERDICTS = ['2600', '2800'];

    /** The faults that refuse the question itself. */
    private const array REFUSED_QUESTIONS = ['11', '2500', '3000'];

    public function validate(?string $registrationId, string $memberState): VatIdValidation
    {
        $state = $this->registerCode($memberState);
        $number = strtoupper(preg_replace('/\s+/', '', $registrationId ?? '') ?? '');

        if ($number === '') {
            return VatIdValidation::Unavailable;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['SOAPAction' => '""'])
                ->withBody($this->envelope($number, $state), 'text/xml; charset=utf-8')
                ->post(self::ENDPOINT);
        } catch (Throwable) {
            return VatIdValidation::Unavailable;
        }

        // A fault arrives with HTTP 500 and an answer with 200, so the body decides, not the status. A body that
        // is neither, a maintenance page for instance, reads as no answer.
        $xpath = $this->xpath($response->body());

        if (! $xpath instanceof DOMXPath) {
            return VatIdValidation::Unavailable;
        }

        $fault = $this->text($xpath, '/env:Envelope/env:Body/env:Fault/faultstring');

        return $fault === null
            ? $this->verdictOnAnswer($xpath, $state)
            : $this->verdictOnFault($fault, $state);
    }

    /** The two-letter code the register knows the member state by. */
    private function registerCode(string $memberState): string
    {
        $code = strtoupper(trim($memberState));

        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The member state of exemption has to be a two-letter code, and "%s" is not one. The exemption '
                .'is asked for the member state the supply is taxed in, which is never the one the business is '
                .'established in.',
                $memberState,
            ));
        }

        return $code === 'GR' ? 'EL' : $code;
    }

    /** The request, in the form the service was measured to answer. */
    private function envelope(string $number, string $state): string
    {
        return sprintf(
            '<soapenv:Envelope xmlns:soapenv="%s" xmlns:typ="%s"><soapenv:Header/><soapenv:Body><typ:SmeRequest>'
            .'<typ:number>%s</typ:number><typ:msOfExemption><typ:msCode>%s</typ:msCode></typ:msOfExemption>'
            .'</typ:SmeRequest></soapenv:Body></soapenv:Envelope>',
            self::ENVELOPE_NAMESPACE,
            self::TYPES_NAMESPACE,
            htmlspecialchars($number, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $state,
        );
    }

    private function verdictOnFault(string $fault, string $state): VatIdValidation
    {
        preg_match_all('/SOW-ERR-(\d+)/', $fault, $matches);
        $codes = $matches[1];

        if (array_intersect($codes, self::REFUSED_QUESTIONS) !== []) {
            throw new InvalidArgumentException(sprintf(
                'The small-business register refused the question for member state %s rather than answering it: %s. '
                .'Ask for a member state other than the one the business is established in, by the code the '
                .'register uses (EL for Greece).',
                $state,
                trim((string) preg_replace('/^.*?(?=SOW-ERR-)/s', '', $fault), " ;.\n"),
            ));
        }

        return array_intersect($codes, self::NUMBER_VERDICTS) === []
            ? VatIdValidation::Unavailable
            : VatIdValidation::Invalid;
    }

    private function verdictOnAnswer(DOMXPath $xpath, string $state): VatIdValidation
    {
        $answer = '/env:Envelope/env:Body/sme:SmeResponse';
        $valid = $this->boolean($this->text($xpath, $answer.'/sme:valid'));

        if ($valid !== true) {
            return $valid === false ? VatIdValidation::Invalid : VatIdValidation::Unavailable;
        }

        $granted = $this->boolean($this->text(
            $xpath,
            sprintf('%s/sme:msOfExemption/sme:memberState[sme:msCode = "%s"]/sme:exemptionGranted', $answer, $state),
        ));

        return match ($granted) {
            true => VatIdValidation::Valid,
            false => VatIdValidation::Invalid,
            null => VatIdValidation::Unavailable,
        };
    }

    private function xpath(string $body): ?DOMXPath
    {
        if (trim($body) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('env', self::ENVELOPE_NAMESPACE);
        $xpath->registerNamespace('sme', self::TYPES_NAMESPACE);

        return $xpath;
    }

    private function text(DOMXPath $xpath, string $expression): ?string
    {
        $nodes = $xpath->query($expression);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : null;
    }

    /** An `xs:boolean`, or null when the value is none. */
    private function boolean(?string $value): ?bool
    {
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
