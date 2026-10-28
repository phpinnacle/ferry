<?php

namespace PHPinnacle\Ferry\Commands;

use Illuminate\Console\Command;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Services\ConnectorManager;
use Throwable;

class RefreshConnectorStatuses extends Command
{
    protected $signature = 'ferry:refresh';

    protected $description = 'Refresh the statuses of all saved Ferry connectors';

    public function handle(ConnectorManager $connectors): int
    {
        $connectors->refreshStatuses(function (Connector $connector, Throwable $exception) {
            $this->warn($connector->name . ': ' . $exception->getMessage());
        });

        $this->info('Connector status refresh completed.');

        return self::SUCCESS;
    }
}
