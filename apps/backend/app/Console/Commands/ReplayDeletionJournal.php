<?php

namespace App\Console\Commands;

use App\Support\Privacy\OrganizationPurger;
use App\Support\Privacy\StoreDeletion;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayDeletionJournal extends Command
{
    protected $signature = 'privacy:replay-deletions {journal : JSON lines written by privacy:deletion-journal; - reads them from stdin}';

    protected $description = 'After restoring a backup, apply all organization and store deletions in the newest journal before opening the service.';

    public function handle(OrganizationPurger $purger, StoreDeletion $storeDeletion): int
    {
        $path = (string) $this->argument('journal');

        if ($path !== '-' && (! is_file($path) || ! is_readable($path))) {
            $this->components->error('The journal file cannot be read.');

            return self::FAILURE;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($path === '-' ? 'php://stdin' : $path))), fn (string $line): bool => $line !== ''));
        $organizations = [];
        $stores = [];

        foreach ($lines as $number => $line) {
            $entry = json_decode($line, true);

            $kind = is_array($entry) ? ($entry['kind'] ?? 'organization') : null;

            if (! is_array($entry) || ! is_string($entry['tenant_id'] ?? null)
                || ($kind === 'organization' && ! is_string($entry['request_id'] ?? null))
                || ($kind === 'store' && ! is_string($entry['store_id'] ?? null))
                || ! in_array($kind, ['organization', 'store'], true)) {
                $this->components->error(sprintf('Line %d is not a journal entry.', $number + 1));

                return self::FAILURE;
            }

            if ($kind === 'store') {
                $stores[] = $entry;
            } else {
                $organizations[] = $entry;
            }
        }

        $replayed = $purger->replay($organizations);

        foreach ($organizations as $entry) {
            DB::table('data_deletion_journal')->insertOrIgnore([
                'request_id' => $entry['request_id'],
                'tenant_id' => $entry['tenant_id'],
                'requested_via' => $entry['requested_via'] ?? 'platform',
                'requested_at' => $entry['requested_at'] ?? now(),
                'completed_at' => $entry['completed_at'] ?? now(),
            ]);
        }

        $storesReplayed = 0;

        foreach ($stores as $entry) {
            DB::transaction(function () use ($entry, $storeDeletion, &$storesReplayed): void {
                $store = Store::query()->where('tenant_id', $entry['tenant_id'])->whereKey($entry['store_id'])->lockForUpdate()->first();

                if ($store !== null) {
                    $storeDeletion->delete($store);
                    $storesReplayed++;
                }

                DB::table('store_deletion_journal')->insertOrIgnore([
                    'store_id' => $entry['store_id'], 'tenant_id' => $entry['tenant_id'], 'deleted_at' => $entry['deleted_at'] ?? now(),
                ]);
            });
        }

        $this->components->info(sprintf('Journal entries: %d, organizations deleted again: %d, stores deleted again: %d.', count($organizations) + count($stores), $replayed, $storesReplayed));

        return self::SUCCESS;
    }
}
