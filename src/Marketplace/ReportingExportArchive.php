<?php

declare(strict_types=1);

namespace Pushery\Billing\Marketplace;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Pushery\Billing\Models\ReportingExportRecord;
use Pushery\Billing\Tax\FilingCalendar;

/**
 * Keeps what was reported about sellers for a period — the bytes, not the figures.
 *
 * ## Why the bytes
 *
 * A file is a thing on a disk. It can be moved, regenerated, overwritten or edited between production and
 * filing, and none of that leaves a trace. A year later "which figures did we actually report" has no
 * answer, only a file somebody may have touched since.
 *
 * Storing the figures instead would answer a different question. It would say what the package believes
 * today, and what the package believes today is exactly what a dispute is about.
 *
 * ## A second run is a second row
 *
 * Producing a period twice is normal — figures move as late corrections land — and the interesting fact is
 * that it happened and whether the two agree. There is deliberately no unique key on the period: an archive
 * that replaced the earlier row would destroy the only evidence that the numbers changed.
 *
 * ## It does not know the format
 *
 * Rendered content comes IN. That is not indifference, it is the seam: which format a jurisdiction reports
 * in is the profile's business, and an archive that could render would have to be taught every format it is
 * ever asked to keep. It records WHICH format and WHICH version, so a reader years later needs neither the
 * renderer nor the release that produced the bytes in order to know what they are looking at.
 *
 * ## It files nothing
 *
 * The package holds no portal credentials and transmits nothing. It writes where it is told — a disk, a
 * path, or nowhere at all — and hands the operator a file. The same line {@see FilingCalendar}
 * draws: it warns, it does not file.
 */
final readonly class ReportingExportArchive
{
    public function __construct(
        private Repository $config,
        private FilesystemFactory $filesystems,
    ) {}

    /**
     * Record a produced report, and put a copy where configuration points.
     *
     * @param  string  $contents  the exact bytes, already rendered
     * @param  int  $sellerCount  how many sellers they cover — kept beside the bytes because "did that year
     *                            really have four sellers?" is asked before anybody opens the file
     */
    public function store(
        int $year,
        string $currency,
        string $format,
        string $formatVersion,
        string $contents,
        int $sellerCount,
        ?CarbonInterface $at = null,
    ): ReportingExportRecord {
        return ReportingExportRecord::query()->create([
            'period_year' => $year,
            'currency' => strtoupper($currency),
            'format' => $format,
            'format_version' => $formatVersion,
            'generated_at' => $at ?? Carbon::now(),
            'seller_count' => $sellerCount,
            'checksum' => hash('sha256', $contents),
            'contents' => $contents,
            'written_to' => $this->put($year, $currency, $format, $contents),
        ]);
    }

    /**
     * Whether a period would produce today exactly what a stored run produced.
     *
     * Compared by FINGERPRINT rather than field by field, and that is the difference between a real check
     * and a reassuring one. A field comparison only ever asks about the fields somebody thought to compare;
     * the bytes are what was reported, so they are what has to match.
     *
     * A mismatch is not an error. It is the ordinary consequence of a late correction — but it is a fact
     * somebody has to see BEFORE filing, rather than learn afterwards from an authority.
     */
    public function matchesStored(ReportingExportRecord $stored, string $contents): bool
    {
        return hash_equals($stored->checksum, hash('sha256', $contents));
    }

    /**
     * Every run of a period, oldest first, so a second run can be compared against the one before it.
     *
     * @return Collection<int, ReportingExportRecord>
     */
    public function runsFor(int $year, string $currency, ?string $format = null): Collection
    {
        return ReportingExportRecord::query()
            ->where('period_year', $year)
            ->where('currency', strtoupper($currency))
            ->when($format !== null, fn (Builder $query): Builder => $query->where('format', $format))
            ->orderBy('generated_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * A copy on a configured disk, or null where none is configured.
     *
     * Null is a supported answer rather than a misconfiguration: an operator whose accounting collects the
     * file from the record itself needs no second copy, and writing one anyway would put a document
     * containing sellers' figures somewhere nobody asked for it.
     */
    private function put(int $year, string $currency, string $format, string $contents): ?string
    {
        $disk = $this->config->get('billing.marketplace.reporting.export_disk');
        $path = $this->config->get('billing.marketplace.reporting.export_path', 'reporting');

        if (! is_string($disk) || $disk === '') {
            return null;
        }

        // The year first, so a directory sorts into filing order rather than alphabetically by format.
        $file = rtrim(is_string($path) ? $path : 'reporting', '/')
            .sprintf('/%d-%s-%s.txt', $year, strtolower($format), strtolower($currency));

        $this->filesystems->disk($disk)->put($file, $contents);

        return $disk.':'.$file;
    }
}
