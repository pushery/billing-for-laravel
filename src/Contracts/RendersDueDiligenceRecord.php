<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A renderer that is handed every seller the period examined, not only the ones a duty reports.
 *
 * ## Why a renderer has to ask for them
 *
 * A reporting duty covers some sellers and not others, and transmitting one it does not cover is a return
 * that is not correct in its own right. The same kind of duty usually asks the operator to keep a record of
 * the due diligence for EVERY seller it was applied to: who was examined, when, and with which result. Those
 * are two different artifacts, and a renderer produces one or the other.
 *
 * So the export decides what a renderer is handed by what the renderer is. A plain
 * {@see RendersReportingRecord} receives the reportable sellers and nothing else, together with their records
 * and nobody else's. A renderer that implements this contract receives every examined seller, and each
 * `SellerPeriodReport` still answers `reportable()`, so the verdict can be written beside the row.
 *
 * This used to be a paragraph in the documentation telling a renderer to filter. A consumer who writes a wire
 * format and misses that paragraph transmits sellers the duty does not cover, with their income, and nothing
 * downstream notices. Asking for the full list is a declaration in the type now, where it can't be missed.
 */
interface RendersDueDiligenceRecord extends RendersReportingRecord {}
