<?php

namespace App\Console\Commands;

use App\Support\Plugin\PluginFunnel;
use Illuminate\Console\Command;

class PrunePluginFunnel extends Command
{
    protected $signature = 'plugin-funnel:prune';

    protected $description = 'Delete plugin connection funnel events older than 13 months';

    public function handle(PluginFunnel $funnel): int
    {
        $this->line('deleted='.$funnel->prune());

        return self::SUCCESS;
    }
}
