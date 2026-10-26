<?php

namespace PHPinnacle\Ferry\Enums;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Enums\Alignment;
use PHPinnacle\Ferry\Models\Upstream;
use PHPinnacle\Ferry\Resources\Upstreams\Actions\TestUpstreamAction;

enum Driver: string implements HasLabel
{
    case Pgsql = 'pgsql';
    case Sqlsrv = 'sqlsrv';

    public function getLabel(): string
    {
        return __('phpinnacle-ferry::enums.driver.' . $this->value);
    }

    /** @return list<Component> */
    public function schema(): array
    {
        return [
            Section::make()
                ->columns(3)
                ->schema([
                    TextInput::make('host')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.host'))
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('port')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.port'))
                        ->integer()
                        ->default($this->defaultPort())
                        ->minValue(1)
                        ->maxValue(65_535)
                        ->required(),
                    TextInput::make('database')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.database'))
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('username')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.username'))
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('password')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.password'))
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->maxLength(255)
                        ->afterStateHydrated(function (TextInput $component, ?Upstream $record) {
                            if ($record !== null) {
                                $component->state(null);
                            }
                        })
                        ->dehydrateStateUsing(fn (?string $state, ?Upstream $record) => filled($state)
                            ? $state
                            : $record?->config['password'])
                        ->required(fn (?Upstream $record) => $record === null),
                    ...match ($this) {
                        self::Pgsql => [
                            TextInput::make('schema')
                                ->label(__('phpinnacle-ferry::resources.upstream.fields.schema'))
                                ->maxLength(255),
                            Select::make('sslmode')
                                ->label(__('phpinnacle-ferry::resources.upstream.fields.sslmode'))
                                ->options(SSLMode::class)
                                ->dehydrateStateUsing(fn (?SSLMode $state) => $state?->value),
                        ],
                        self::Sqlsrv => [],
                    },
                ])
                ->footerActions([
                    TestUpstreamAction::make(),
                ])
                ->footerActionsAlignment(Alignment::End),
        ];
    }

    private function defaultPort(): int
    {
        return match ($this) {
            self::Pgsql => 5432,
            self::Sqlsrv => 1433,
        };
    }
}
