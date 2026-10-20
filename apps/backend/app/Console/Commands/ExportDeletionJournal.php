<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportDeletionJournal extends Command
{
    protected $signature = 'privacy:deletion-journal';

    protected $description = 'Print completed organization and store deletions as JSON lines next to each backup.';

    public function handle(): int
    {
        DB::table('data_deletion_journal')->orderBy('completed_at')->orderBy('request_id')->get()->each(function ($entry): void {
            $this->line(json_encode([
                'kind' => 'organization',
                'request_id' => $entry->request_id,
                'tenant_id' => $entry->tenant_id,
                'requested_via' => $entry->requested_via,
                'requested_at' => $entry->requested_at,
                'completed_at' => $entry->completed_at,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        });

        DB::table('store_deletion_journal')->orderBy('deleted_at')->orderBy('store_id')->get()->each(function ($entry): void {
            $this->line(json_encode([
                'kind' => 'store',
                'tenant_id' => $entry->tenant_id,
                'store_id' => $entry->store_id,
                'deleted_at' => $entry->deleted_at,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        });

        return self::SUCCESS;
    }
}
