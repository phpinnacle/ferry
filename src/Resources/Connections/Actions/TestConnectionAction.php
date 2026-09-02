<?php

namespace PHPinnacle\Ferry\Resources\Connections\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Services\ConnectionTester;
use PHPinnacle\Ferry\Services\ConnectionTestResult;

class TestConnectionAction
{
    public static function form(): Action
    {
        return Action::make('test_connection')
            ->label(__('phpinnacle-ferry::resources.connection.actions.test_connection'))
            ->icon('phosphor-plugs-connected')
            ->color('gray')
            ->action(function (ConnectionTester $tester, Get $get, ?Connection $record) {
                $password = $get('password');

                if (!filled($password) && $record !== null) {
                    $password = $record->password;
                }

                $driver = $get('driver');

                self::notify($tester->test([
                    'driver' => $driver instanceof Driver ? $driver->value : $driver,
                    'host' => $get('host'),
                    'port' => $get('port'),
                    'database' => $get('database'),
                    'username' => $get('username'),
                    'password' => $password,
                    'schema' => $get('schema'),
                    'ssl_mode' => $get('ssl_mode'),
                ]), $record);
            });
    }

    public static function table(): Action
    {
        return Action::make('test_connection')
            ->label(__('phpinnacle-ferry::resources.connection.actions.test_connection'))
            ->icon('phosphor-plugs-connected')
            ->iconButton()
            ->action(fn (ConnectionTester $tester, Connection $record) => self::notify(
                $tester->test($record->credentials()),
                $record,
            ));
    }

    private static function notify(ConnectionTestResult $result, ?Connection $record): void
    {
        $record?->recordTestResult($result);

        $notification = Notification::make()->title($result->message);

        $result->success ? $notification->success() : $notification->danger();

        $notification->send();
    }
}
