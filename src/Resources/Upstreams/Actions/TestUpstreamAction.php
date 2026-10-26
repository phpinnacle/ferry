<?php

namespace PHPinnacle\Ferry\Resources\Upstreams\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use PHPinnacle\Ferry\Data\UpstreamTestResult;
use PHPinnacle\Ferry\Enums\Driver;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Services\UpstreamFactory;

class TestUpstreamAction
{
    public static function make(): Action
    {
        return Action::make('test_upstream')
            ->label(__('phpinnacle-ferry::resources.upstream.actions.test_upstream'))
            ->icon('phosphor-plugs-connected')
            ->color('gray')
            ->disabled(fn (Get $get) => blank($get('../driver')))
            ->action(function (UpstreamFactory $tester, Section $component, Get $get) {
                self::notify($tester->test([
                    ...$component->getContainer()->getState(),
                    'driver' => $get->enum('../driver', Driver::class)->value,
                ]));
            });
    }

    public static function table(): Action
    {
        return Action::make('test_upstream')
            ->label(__('phpinnacle-ferry::resources.upstream.actions.test_upstream'))
            ->icon('phosphor-plugs-connected')
            ->iconButton()
            ->action(fn (UpstreamFactory $tester, Upstream $record) => self::notify(
                $tester->test($record->credentials()),
            ));
    }

    private static function notify(UpstreamTestResult $result): void
    {
        $notification = Notification::make()->title($result->message);

        $result->success ? $notification->success() : $notification->danger();

        $notification->send();
    }
}
