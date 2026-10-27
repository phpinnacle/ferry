<?php

namespace PHPinnacle\Ferry\Resources\Replicas\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\Rule;
use PHPinnacle\Eidos\Forms\FieldBinding;
use PHPinnacle\Eidos\Forms\FieldMapping;
use PHPinnacle\Ferry\Models\StructureItem;

final class InsertUuidAction
{
    public static function make(?StructureItem $object): Action
    {
        $primaryKey = $object->primary_key ?? [];
        $fields = $object?->mappableFields() ?? [];
        $options = [];
        foreach ($primaryKey as $source) {
            $options[$source] = $fields[$source]['title'] ?? $source;
        }

        return Action::make('insertUuid')
            ->label(__('phpinnacle-ferry::resources.replica.actions.insert_uuid'))
            ->disabled($primaryKey === [])
            ->schema(fn (FieldMapping|FieldBinding $component) => [
                TextInput::make('field')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.uuid_field'))
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->rules(['string', Rule::notIn(array_keys($component->getSourceSchema()['properties'] ?? []))])
                    ->validationMessages([
                        'not_in' => __('phpinnacle-ferry::validation.uuid_field_exists'),
                    ]),
                Select::make('sources')
                    ->label(__('phpinnacle-ferry::resources.replica.fields.uuid_sources'))
                    ->options($options)
                    ->multiple()
                    ->default($primaryKey)
                    ->required()
                    ->minItems(count($primaryKey))
                    ->maxItems(count($primaryKey))
                    ->rules(['array', Rule::in($primaryKey)])
                    ->nestedRecursiveRules(['string', 'distinct']),
            ])
            ->action(function (array $data, FieldMapping|FieldBinding $component, Set $set) use ($primaryKey) {
                /** @var array{field: string, sources: list<string>} $data */
                $sourceSchema = $component->getSourceSchema();
                $sourceSchema['properties'] =
                    [
                        $data['field'] => [
                            'type' => 'string',
                            'format' => 'uuid',
                            'minLength' => 36,
                            'maxLength' => 36,
                        ],
                    ]
                    + ($sourceSchema['properties'] ?? []);
                $sourceSchema['required'] = [
                    ...($sourceSchema['required'] ?? []),
                    $data['field'],
                ];
                $sourceSchema['x-primary-key'] = [$data['field']];
                /** @var list<array<string, mixed>> $state */
                $state = $component->getState();
                array_unshift($state, [
                    'kind' => 'source',
                    'source' => $data['field'],
                    'target' => null,
                    'options' => [
                        'transforms' => [[
                            'type' => 'insert_uuid',
                            'data' => ['fields' => implode(',', $primaryKey)],
                        ]],
                    ],
                ]);
                $set('schema', $sourceSchema);
                $component->state($state);
                if ($component instanceof FieldMapping) {
                    $component->jsonSchema(source: $sourceSchema);
                } else {
                    $component->jsonSchema($sourceSchema);
                }
            });
    }
}
