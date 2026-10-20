<?php

declare(strict_types=1);

namespace Pushery\Billing\Contracts;

/**
 * A jurisdiction profile under which a supply of goods the platform is treated as making itself can be sold.
 *
 * A platform that facilitates certain sales of goods is treated as receiving the goods from the seller and supplying
 * them to the buyer (Art. 14a VAT Directive). Such a sale has two documents, two returns it is declared in, records
 * the platform keeps as the supplier, and corrections of all of them when the buyer gets money back. Which documents
 * those are, and which accounts and returns take them, is a jurisdiction's answer, so a profile declares that it has
 * them.
 *
 * A marker a profile opts into rather than a method on every profile, so adding it breaks no implementation outside
 * this package. Without it a sale classified as such a supply is refused before any money moves, because recording it
 * as anything else would describe a transaction that did not happen.
 */
interface DocumentsDeemedSupplyOfGoods {}
