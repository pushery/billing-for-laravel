<?php

declare(strict_types=1);

namespace Pushery\Billing\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The files a retained record keeps on a disk, removed after the record when its window has run out.
 *
 * Three tables hold rows whose document also lives as a file: an invoice's kept PDF under `pdf_path` on the disk
 * `billing.invoices.pdf_disk`, and a produced tax return, recapitulative statement or seller-reporting file under
 * `written_to`, which names its disk and path as `disk:path`. The file carries the same personal data as the row,
 * so deleting the row alone ends the record and leaves the document on the disk with no end.
 *
 * A file goes after its row, never before, so a row a hold keeps between selection and deletion still finds its
 * file. And it goes only when no remaining row names it: a return produced again for the same period writes to the
 * same path, so an older row and a newer one can name one file, and the older row's window is not the newer one's.
 *
 * A file that is already gone is counted, not an error. A file the disk does not remove is logged with its disk and
 * path and counted as failed, because its row is gone and nothing else will ever find the file again.
 */
final class StoredDocumentFiles
{
    /** table => the column that names the stored file of a row */
    private const array COLUMNS = [
        'billing_invoices' => 'pdf_path',
        'billing_tax_return_exports' => 'written_to',
        'billing_reporting_exports' => 'written_to',
    ];

    private int $removed = 0;

    private int $missing = 0;

    private int $failed = 0;

    private int $pending = 0;

    public function __construct(
        private readonly Repository $config,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * Delete the rows a query selects, then the files they named that no remaining row names.
     *
     * @return int the rows deleted
     */
    public function deleteWithFiles(string $table, Builder $rows): int
    {
        [$ids, $files] = $this->filesNamedBy($table, clone $rows);

        $deleted = $rows->delete();

        foreach ($this->unshared($table, $ids, $files) as [$disk, $path]) {
            $this->remove($disk, $path);
        }

        return $deleted;
    }

    /**
     * Count the rows a query selects, and the files that would go with them, without removing anything.
     *
     * @return int the rows selected
     */
    public function countWithFiles(string $table, Builder $rows): int
    {
        [$ids, $files] = $this->filesNamedBy($table, clone $rows);

        $this->pending += count($this->unshared($table, $ids, $files));

        return $rows->count();
    }

    /** Files removed so far. */
    public function removed(): int
    {
        return $this->removed;
    }

    /** Files a pruned row named that were no longer on their disk. */
    public function missing(): int
    {
        return $this->missing;
    }

    /** Files the disk did not remove, each logged with its disk and path. */
    public function failed(): int
    {
        return $this->failed;
    }

    /** Files a dry run found that a real run would remove. */
    public function pending(): int
    {
        return $this->pending;
    }

    /**
     * The ids of the selected rows that name a file, and the files they name, keyed by the stored value.
     *
     * @return array{list<mixed>, array<string, array{string, string}>}
     */
    private function filesNamedBy(string $table, Builder $rows): array
    {
        $column = self::COLUMNS[$table] ?? null;

        if ($column === null) {
            return [[], []];
        }

        $ids = [];
        $files = [];

        foreach ($rows->whereNotNull($column)->get(['id', $column]) as $row) {
            $value = $row->{$column} ?? null;
            $located = is_string($value) ? $this->locate($table, $value) : null;

            if ($located === null) {
                continue;
            }

            $ids[] = $row->id;
            $files[$value] = $located;
        }

        return [$ids, $files];
    }

    /**
     * Where a stored value points: the configured PDF disk for an invoice, the disk the value itself names otherwise.
     *
     * @return array{string, string}|null
     */
    private function locate(string $table, string $value): ?array
    {
        if (trim($value) === '') {
            return null;
        }

        if ($table === 'billing_invoices') {
            $disk = $this->config->get('billing.invoices.pdf_disk');

            return is_string($disk) && trim($disk) !== '' ? [$disk, $value] : null;
        }

        $separator = strpos($value, ':');

        if (in_array($separator, [false, 0, strlen($value) - 1], true)) {
            return null;
        }

        return [substr($value, 0, $separator), substr($value, $separator + 1)];
    }

    /**
     * The files no row outside the selection names.
     *
     * @param  list<mixed>  $ids
     * @param  array<string, array{string, string}>  $files  stored value => [disk, path]
     * @return list<array{string, string}>
     */
    private function unshared(string $table, array $ids, array $files): array
    {
        $column = self::COLUMNS[$table] ?? null;

        if ($column === null) {
            return [];
        }

        $unshared = [];

        foreach ($files as $value => $file) {
            $named = DB::table($table)->where($column, $value)->whereNotIn('id', $ids)->exists();

            if (! $named) {
                $unshared[] = $file;
            }
        }

        return $unshared;
    }

    private function remove(string $diskName, string $path): void
    {
        try {
            $disk = $this->filesystems->disk($diskName);

            if (! $disk->exists($path)) {
                $this->missing++;

                return;
            }

            if ($disk->delete($path)) {
                $this->removed++;

                return;
            }

            $reason = 'the disk reported that it did not remove the file';
        } catch (Throwable $e) {
            $reason = $e->getMessage();
        }

        $this->failed++;

        Log::error('billing: the file of a pruned record could not be removed', [
            'disk' => $diskName,
            'path' => $path,
            'reason' => $reason,
        ]);
    }
}
