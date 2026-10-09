<?php

namespace App\Console\Commands;

use App\Models\BrowserWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CreateBrowserWorker extends Command
{
    protected $signature = 'browser:worker-create {name : Unique worker name} {--rotate : Replace the token of an existing worker}';

    protected $description = 'Create (or rotate) a browser worker credential. The token is printed once and only its hash is stored.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $token = 'bwwk_'.bin2hex(random_bytes(32));
        $existing = BrowserWorker::query()->where('name', $name)->first();

        if ($existing !== null && ! $this->option('rotate')) {
            $this->components->error('A worker with this name exists; use --rotate to replace its token.');

            return self::FAILURE;
        }

        if ($existing !== null) {
            $existing->forceFill(['token_hash' => hash('sha256', $token), 'status' => BrowserWorker::STATUS_ACTIVE, 'revoked_at' => null])->save();
        } else {
            BrowserWorker::query()->create([
                'name' => $name,
                'token_hash' => hash('sha256', $token),
                'status' => BrowserWorker::STATUS_ACTIVE,
                'created_at' => Carbon::now(),
            ]);
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
