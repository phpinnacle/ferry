<?php

namespace PHPinnacle\Ferry\Services;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPinnacle\Ferry\Contracts\Adapter;
use PHPinnacle\Ferry\Data\CapturePlan;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Models\Upstream;

class EloquentAdapter implements Adapter
{
    public function getKey(): string
    {
        return 'eloquent';
    }

    public function getLabel(): string
    {
        return 'Eloquent';
    }

    public function form(): array
    {
        return [
            Section::make(__('phpinnacle-ferry::resources.upstream.sections.connection'))
                ->columns(3)
                ->schema([
                    Select::make('connection')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.connection'))
                        ->options(function () {
                            /** @var array<string, array{driver: string, ...}> $connections */
                            $connections = Config::array('database.connections');
                            $connections = array_keys(array_filter(
                                $connections,
                                fn (array $connection) => $connection['driver'] === 'pgsql',
                            ));

                            return array_combine($connections, $connections);
                        })
                        ->required()
                        ->disabledOn('edit')
                        ->dehydrated()
                        ->dehydrateStateUsing(
                            fn (string $state, ?Upstream $record) => $record?->config['connection'] ?? $state,
                        )
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('models', [])),
                    Select::make('models')
                        ->label(__('phpinnacle-ferry::resources.upstream.fields.models'))
                        ->columnSpan(['lg' => 2])
                        ->multiple()
                        ->searchable()
                        ->options(function (Get $get, ?Upstream $record) {
                            /** @var array{connection: string, models: list<class-string<Model>>}|null $config */
                            $config = $record?->config;

                            return $this->models($config['connection'] ?? $get->string('connection', isNullable: true));
                        })
                        ->required(),
                ]),
        ];
    }

    public function capture(Upstream $upstream): CapturePlan
    {
        /** @var array{connection: string, models: list<class-string<Model>>} $config */
        $config = $upstream->config;
        $tables = array_map(
            fn (string $table) => preg_quote($table, '/'),
            array_keys(EloquentModels::byTable($config['models'])),
        );

        /** @var array{driver: string, ...} $connection */
        $connection = DB::connection($config['connection'])->getConfig();

        return new CapturePlan($connection, $tables, converters: [
            'key.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'key.converter.schemas.enable' => 'false',
            'value.converter' => 'org.apache.kafka.connect.json.JsonConverter',
            'value.converter.schemas.enable' => 'false',
        ]);
    }

    public function prepare(Upstream $upstream): StructurePlan
    {
        return new StructurePlan(0);
    }

    public function read(Upstream $upstream, StructurePlan $plan, int $offset, int $limit): array
    {
        return [];
    }

    /** @return array<class-string<Model>, string> */
    private function models(?string $connection): array
    {
        if ($connection === null) {
            return [];
        }

        $models = [];

        foreach (FerryPlugin::get()->getModels() as $class) {
            if (new $class()->getConnection()->getName() === $connection) {
                $models[$class] = $class;
            }
        }

        return $models;
    }
}
