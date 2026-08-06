<?php

declare(strict_types=1);

namespace Pushery\Billing\Tax;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Pushery\Billing\Models\TaxReturnExportRecord;
use Pushery\Billing\ValueObjects\ReportingPeriod;
use Pushery\Billing\ValueObjects\TaxReturnLine;

/**
 * Keeps a produced return file as the document it is, and puts a copy where the operator asked.
 *
 * ## Why the file alone is not enough
 *
 * A file is a thing on a disk. It can be moved, re-generated, overwritten, or edited between production and
 * filing, and none of that leaves a trace. A year later "which figures did we actually file for that quarter"
 * has no answer — only a file somebody may have touched since. So the bytes are recorded here with the
 * moment they were produced and a fingerprint, and the record cannot be edited.
 *
 * ## A second run is a second row
 *
 * Not an overwrite. Producing a period twice is normal — figures move as late corrections land — and the
 * interesting fact is precisely that it happened and whether the two agree. An archive that replaced the
 * earlier row would destroy the only evidence that the numbers changed, which is the one thing anybody would
 * want to see.
 *
 * ## Where the copy goes is configuration
 *
 * The package does not file anything with anybody and knows no portal credentials. It writes where it is
 * told — a disk, a path, or nowhere at all — and hands the operator's accounting the file to check.
 */
final readonly class TaxReturnExportArchive
{
    public function __construct(
        private PeriodicTaxReturnExport $export,
        private Repository $config,
        private FilesystemFactory $filesystems,
    ) {}

    /**
     * Produce a period's file, record it, and put a copy where configuration points.
     *
     * @param  list<TaxReturnLine>  $lines
     */
    public function store(ReportingPeriod $period, array $lines, string $currency, ?CarbonInterface $at = null): TaxReturnExportRecord
    {
        $contents = $this->export->render($period, $lines);
        $moment = $at ?? Carbon::now();

        return TaxReturnExportRecord::query()->create([
            'year' => $period->year,
            'quarter' => $period->quarter,
            'period_label' => $period->label(),
            'currency' => strtoupper($currency),
            'generated_at' => $moment,
            'line_count' => count($lines),
            'net_minor' => array_sum(array_map(fn (TaxReturnLine $line): int => $line->netMinor, $lines)),
            'tax_minor' => array_sum(array_map(fn (TaxReturnLine $line): int => $line->taxMinor, $lines)),
            'checksum' => hash('sha256', $contents),
            'contents' => $contents,
            'written_to' => $this->put($period, $contents, $currency),
        ]);
    }

    /**
     * Whether a period would produce today exactly what the stored run produced.
     *
     * The point of asking: a return is reproducible only if nothing under it moved. A mismatch is not an
     * error — it is the normal consequence of a late correction — but it is a fact somebody has to see
     * BEFORE filing, not discover afterwards from a tax authority.
     *
     * @param  list<TaxReturnLine>  $lines
     */
    public function matchesStored(TaxReturnExportRecord $stored, ReportingPeriod $period, array $lines): bool
    {
        return $stored->checksum === hash('sha256', $this->export->render($period, $lines));
    }

    /** Every run of a period, oldest first, so a second run can be compared against the one before it. */
    /** @return Collection<int, TaxReturnExportRecord> */
    public function runsFor(ReportingPeriod $period, string $currency): Collection
    {
        return TaxReturnExportRecord::query()
            ->where('year', $period->year)
            ->where('quarter', $period->quarter)
            ->where('currency', strtoupper($currency))
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get();
    }

    /** The copy on a disk, if configuration asks for one. Null when it does not — writing nowhere is valid. */
    private function put(ReportingPeriod $period, string $contents, string $currency): ?string
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
