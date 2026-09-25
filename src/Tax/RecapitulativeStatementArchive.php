<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\TaxReturnExportRecord;
use Pushery\Billing\ValueObjects\RecapitulativeStatementLine;
use Pushery\Billing\ValueObjects\RecapitulativeStatementPeriod;
use Pushery\Billing\ValueObjects\ReportingPeriod;

/**
 * Keeps a produced recapitulative statement the way the one-stop-shop return is kept.
 *
 * The same table and the same reasons ({@see TaxReturnExportArchive}): the bytes with the moment they were
 * produced and a fingerprint, a second run as a second row, and a copy where configuration points. The row
 * says which declaration it is, so neither archive's runs ever reach the other's comparison. The copy goes
 * to the directory the one-stop-shop files go to, because both are the operator's tax declarations and one
 * place for them is the place somebody looks.
 */
final readonly class RecapitulativeStatementArchive
{
    public function __construct(
        private RecapitulativeStatementExport $export,
        private Repository $config,
        private FilesystemFactory $filesystems,
    ) {}

    /**
     * Produce a period's file, record it, and put a copy where configuration points.
     *
     * @param  list<RecapitulativeStatementLine>  $lines
     */
    public function store(ReportingPeriod|RecapitulativeStatementPeriod $period, array $lines, string $currency, ?CarbonInterface $at = null): TaxReturnExportRecord
    {
        $period = $period instanceof ReportingPeriod ? RecapitulativeStatementPeriod::quarter($period) : $period;
        $contents = $this->export->render($period, $lines);

        return TaxReturnExportRecord::model()::query()->create([
            'return_type' => TaxReturnExportRecord::RECAPITULATIVE_STATEMENT,
            // A month is kept under the quarter it falls in, and told apart by its own label.
            'year' => $period->year,
            'quarter' => $period->quarter,
            'period_label' => $period->label(),
            'currency' => strtoupper($currency),
            'generated_at' => $at ?? Carbon::now(),
            'line_count' => count($lines),
            'net_minor' => array_sum(array_map(static fn (RecapitulativeStatementLine $line): int => $line->netMinor, $lines)),
            // A reverse charge states no tax: the buyer accounts for it in their own return.
            'tax_minor' => 0,
            'checksum' => hash('sha256', $contents),
            'contents' => $contents,
            'written_to' => $this->put($period, $contents, $currency),
        ]);
    }

    /** The copy on a disk, if configuration asks for one. Null when it does not; writing nowhere is valid. */
    private function put(RecapitulativeStatementPeriod $period, string $contents, string $currency): ?string
    {
        $disk = $this->config->get('billing.tax_oss.export_disk');

        if (! is_string($disk) || $disk === '') {
            return null;
        }

        $directory = $this->config->get('billing.tax_oss.export_path');
        $path = trim(is_string($directory) ? $directory : 'tax-returns', '/')
            .'/'.$this->export->filenameFor($period, $currency);

        $this->filesystems->disk($disk)->put($path, $contents);

        return $disk.':'.$path;
    }
}
