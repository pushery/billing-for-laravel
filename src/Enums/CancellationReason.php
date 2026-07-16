<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why an owner canceled — captured for churn analytics by the optional cancellation survey. The
 * human-readable labels live in the i18n layer; these are the stable keys stored with the survey.
 */
enum CancellationReason: string
{
    case TooExpensive = 'too_expensive';
    case MissingFeatures = 'missing_features';
    case NotUsingEnough = 'not_using_enough';
    case SwitchedProvider = 'switched_provider';
    case TechnicalIssues = 'technical_issues';
    case NoLongerNeeded = 'no_longer_needed';
    case Other = 'other';

    /** Whether choosing this reason should require a free-text detail (only "Other"). */
    public function detailRequired(): bool
    {
        return $this === self::Other;
    }
}
