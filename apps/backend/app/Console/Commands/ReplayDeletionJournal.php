<?php

namespace App\Console\Commands;

use App\Support\Privacy\OrganizationPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayDeletionJournal extends Command
{
    protected $signature = 'privacy:replay-deletions {journal : JSON lines written by privacy:deletion-journal; - reads them from stdin}';

    protected $description = 'After restoring a backup, delete again every organization of the newest deletion journal before the service is opened.';

    public function handle(OrganizationPurger $purger): int
    {
        $path = (string) $this->argument('journal');

        if ($path !== '-' && (! is_file($path) || ! is_readable($path))) {
            $this->components->error('The journal file cannot be read.');

            return self::FAILURE;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($path === '-' ? 'php://stdin' : $path))), fn (string $line): bool => $line !== ''));
        $entries = [];

        foreach ($lines as $number => $line) {
            $entry = json_decode($line, true);

            if (! is_array($entry) || ! is_string($entry['tenant_id'] ?? null) || ! is_string($entry['request_id'] ?? null)) {
                $this->components->error(sprintf('Line %d is not a journal entry.', $number + 1));

                return self::FAILURE;
            }

            $entries[] = $entry;
        }

        $replayed = $purger->replay($entries);

        foreach ($entries as $entry) {
            DB::table('data_deletion_journal')->insertOrIgnore([
                'request_id' => $entry['request_id'],
                'tenant_id' => $entry['tenant_id'],
                'requested_via' => $entry['requested_via'] ?? 'platform',
                'requested_at' => $entry['requested_at'] ?? now(),
                'completed_at' => $entry['completed_at'] ?? now(),
            ]);
        }

        $this->components->info(sprintf('Journal entries: %d, organizations deleted again: %d.', count($entries), $replayed));

        return self::SUCCESS;
    }
}
