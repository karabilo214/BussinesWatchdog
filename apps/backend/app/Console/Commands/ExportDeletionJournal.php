<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportDeletionJournal extends Command
{
    protected $signature = 'privacy:deletion-journal';

    protected $description = 'Print the deletion journal as JSON lines (organization and request IDs only), to keep next to each backup.';

    public function handle(): int
    {
        DB::table('data_deletion_journal')->orderBy('completed_at')->orderBy('request_id')->get()->each(function ($entry): void {
            $this->line(json_encode([
                'request_id' => $entry->request_id,
                'tenant_id' => $entry->tenant_id,
                'requested_via' => $entry->requested_via,
                'requested_at' => $entry->requested_at,
                'completed_at' => $entry->completed_at,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        });

        return self::SUCCESS;
    }
}
