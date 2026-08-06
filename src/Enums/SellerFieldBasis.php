<?php

declare(strict_types=1);

namespace Pushery\Billing\Enums;

/**
 * Why a piece of information about a seller is being collected.
 *
 * The distinction is not bookkeeping. Some fields are collected because a law requires them for this
 * particular seller; others are collected in advance, because a seller's classification can change at any
 * time and chasing the data afterwards — after a year has closed, under a filing deadline, from people who
 * have gone quiet — is the expensive case this whole approach exists to avoid.
 *
 * Recording which is which keeps a records-of-processing entry and a privacy notice honest. Declaring
 * everything as legally required would be the same overreach on the COLLECTION side that over-reporting is
 * on the reporting side: a claim that a law demands something it does not.
 */
enum SellerFieldBasis: string
{
    /** A law requires this, for this seller, now. */
    case Required = 'required';

    /** Collected ahead of a duty that does not currently apply, and might. */
    case Precautionary = 'precautionary';
}
