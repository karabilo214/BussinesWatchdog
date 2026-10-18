<?php

namespace App\Console\Commands;

use App\Support\Platform\ServiceAlerts;
use Illuminate\Console\Command;

class SendServiceAlerts extends Command
{
    protected $signature = 'service:alerts';

    protected $description = 'E-mail the responsible person when the service state becomes a problem and when it recovers (ADR 0051).';

    public function handle(ServiceAlerts $alerts): int
    {
        $result = $alerts->run();
        $this->line(sprintf('state=%s sent=%s', $result['state'], $result['sent'] ?? 'none'));

        return self::SUCCESS;
    }
}
