<?php

namespace PHPinnacle\Ferry\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use PHPinnacle\Eidos\Infolists\JsonSchemaEntry;
use PHPinnacle\Ferry\Data\RegisteredSchema;
use PHPinnacle\Ferry\Exceptions\SchemaPreviewUnavailable;
use PHPinnacle\Ferry\Services\AvroSchemaConverter;
use PHPinnacle\Ferry\Services\SchemaRegistryClient;
use PHPinnacle\Jason\Schema as JsonSchema;
use UnexpectedValueException;

class ViewSchemaRegistrySubject extends Page
{
    protected static ?string $slug = 'schema-registry/subject';

    #[Locked]
    public string $subject;

    #[Url(as: 'deleted', except: false)]
    public bool $showDeleted = false;

    #[Locked]
    public ?int $version = null;

    /** @var list<int>|null */
    private ?array $cachedVersions = null;

    /** @var array<int, RegisteredSchema> */
    private array $registeredSchemas = [];

    /** @var array<int, string> */
    private array $resolvedAvroSchemas = [];

    private SchemaRegistryClient $registry;

    private AvroSchemaConverter $avro;

    private bool $unavailable = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return SchemaRegistry::canAccess();
    }

    public function boot(SchemaRegistryClient $registry, AvroSchemaConverter $avro): void
    {
        $this->registry = $registry;
        $this->avro = $avro;
    }

    public function mount(): void
    {
        /** @var array{subject: string} $data */
        $data = validator(request()->query(), ['subject' => ['required', 'string']])->validate();
        $this->subject = $data['subject'];
    }

    public function getTitle(): string
    {
        return $this->subject;
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            SchemaRegistry::getUrl($this->showDeleted ? ['deleted' => true] : []) =>
                SchemaRegistry::getNavigationLabel(),
            $this->subject,
        ];
    }

    public function content(Schema $schema): Schema
    {
        try {
            $compatibility = $this->registry->compatibility($this->subject);
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            report($exception);
            $compatibility = null;
        }

        return $schema->components(function () use ($compatibility) {
            $version = $this->selectedVersion();

            return (
                $version === null
                    ? [Callout::make(__(
                        'phpinnacle-ferry::schema-registry.' . ($this->unavailable ? 'unavailable' : 'no_versions'),
                    ))]
                    : $this->versionInfo($version, $compatibility)
            );
        });
    }

    /** @return list<int> */
    private function versions(): array
    {
        if ($this->cachedVersions !== null) {
            return $this->cachedVersions;
        }

        try {
            $versions = $this->registry->versions($this->subject, $this->showDeleted);
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            if ($exception instanceof RequestException && $exception->response->notFound()) {
                abort(404);
            }

            report($exception);
            $this->unavailable = true;

            return $this->cachedVersions = [];
        }

        rsort($versions);

        return $this->cachedVersions = $versions;
    }

    /** @return list<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make($this->versionActions())
                ->label(__('phpinnacle-ferry::schema-registry.version'))
                ->badge($this->selectedVersion(...))
                ->icon('phosphor-stack')
                ->button()
                ->color('gray')
                ->visible(fn () => $this->versions() !== []),
            Action::make('refresh')
                ->label(__('phpinnacle-ferry::schema-registry.refresh'))
                ->icon('phosphor-arrow-clockwise')
                ->color('gray')
                ->action('$refresh'),
            $this->restoreVersionAction(),
            $this->deleteVersionAction(false),
            $this->deleteVersionAction(true),
        ];
    }

    /** @return list<Action> */
    private function versionActions(): array
    {
        return array_map(fn (int $version) => Action::make('version_' . $version)
            ->label((string) $version)
            ->icon(fn () => $this->selectedVersion() === $version ? 'phosphor-check' : null)
            ->action(function () use ($version) {
                $this->version = $version;
            }), $this->versions());
    }

    private function restoreVersionAction(): Action
    {
        return Action::make('restore_version')
            ->label(__('phpinnacle-ferry::schema-registry.restore'))
            ->icon('phosphor-arrow-counter-clockwise')
            ->color('gray')
            ->authorize(fn () => Gate::allows('restore_schema_registry'))
            ->visible(fn () => $this->versionIsDeleted() === true)
            ->requiresConfirmation()
            ->mountUsing(function () {
                $this->version ??= $this->versions()[0];
            })
            ->modalHeading(
                fn () => (
                    __('phpinnacle-ferry::schema-registry.restore') . ': ' . $this->subject . ' / ' . $this->version
                ),
            )
            ->modalDescription(__('phpinnacle-ferry::schema-registry.restore_description'))
            ->successNotificationTitle(__('phpinnacle-ferry::schema-registry.restored'))
            ->failureNotificationTitle(__('phpinnacle-ferry::schema-registry.restore_failed'))
            ->failureNotificationBody(__('phpinnacle-ferry::schema-registry.restore_failed_description'))
            ->action(function (Action $action) {
                abort_unless(SchemaRegistry::canAccess(), 403);
                Gate::authorize('restore_schema_registry');

                try {
                    $restored = $this->registry->restoreVersion($this->subject, $this->version ?? $this->versions()[0]);
                } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
                    report($exception);
                    $action->failure();

                    return;
                }

                $this->version = $restored->version;
                $this->cachedVersions = null;
                $this->registeredSchemas = [];
                $this->resolvedAvroSchemas = [];

                foreach ($this->getCachedHeaderActions() as $headerAction) {
                    if ($headerAction instanceof ActionGroup) {
                        $headerAction->actions($this->versionActions());

                        foreach ($headerAction->getFlatActions() as $versionAction) {
                            $this->cacheAction($versionAction);
                        }
                    }
                }

                $action->success();
            });
    }

    private function deleteVersionAction(bool $permanent): Action
    {
        $ability = $permanent ? 'force_delete_schema_registry' : 'delete_schema_registry';
        $label = __('phpinnacle-ferry::schema-registry.' . ($permanent ? 'force_delete_version' : 'delete_version'));

        return Action::make($permanent ? 'force_delete_version' : 'delete_version')
            ->label(__('filament-actions::delete.single.label'))
            ->icon($permanent ? 'phosphor-trash-simple' : 'phosphor-trash')
            ->color('danger')
            ->authorize(fn () => Gate::allows($ability))
            ->visible(fn () => $this->versionIsDeleted() === $permanent)
            ->requiresConfirmation()
            ->mountUsing(function () {
                $this->version ??= $this->versions()[0];
            })
            ->modalHeading(fn () => $label . ': ' . $this->subject . ' / ' . $this->version)
            ->modalDescription(__(
                'phpinnacle-ferry::schema-registry.'
                . ($permanent ? 'force_delete_version_description' : 'delete_version_description'),
            ))
            ->successNotificationTitle(__('phpinnacle-ferry::schema-registry.version_deleted'))
            ->failureNotificationTitle(__('phpinnacle-ferry::schema-registry.delete_failed'))
            ->failureNotificationBody(__('phpinnacle-ferry::schema-registry.delete_failed_description'))
            ->action(function (Action $action) use ($permanent, $ability) {
                abort_unless(SchemaRegistry::canAccess(), 403);
                Gate::authorize($ability);

                try {
                    $this->registry->deleteVersion($this->subject, $this->version ?? $this->versions()[0], $permanent);
                } catch (ConnectionException|RequestException $exception) {
                    report($exception);
                    $action->failure();

                    return;
                }

                $action->success();
                $this->redirect(SchemaRegistry::getUrl($this->showDeleted ? ['deleted' => true] : []));
            });
    }

    private function versionIsDeleted(): ?bool
    {
        $version = $this->selectedVersion();

        if ($version === null) {
            return null;
        }

        try {
            return $this->registeredSchema($version)->deleted === true;
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            report($exception);

            return null;
        }
    }

    private function selectedVersion(): ?int
    {
        return $this->version ?? $this->versions()[0] ?? null;
    }

    private function registeredSchema(int $version): RegisteredSchema
    {
        return $this->registeredSchemas[$version] ??= $this->registry->schema(
            $this->subject,
            $version,
            $this->showDeleted,
        );
    }

    /**
     * @return list<Component>
     */
    private function versionInfo(int $version, ?string $compatibility): array
    {
        try {
            $registered = $this->registeredSchema($version);

            return [
                Section::make(__('phpinnacle-ferry::schema-registry.details'))
                    ->afterHeader([
                        Text::make(__('phpinnacle-ferry::schema-registry.deleted'))
                            ->badge()
                            ->color('gray')
                            ->visible($registered->deleted === true),
                    ])
                    ->schema([
                        TextEntry::make('id')->label('ID')->state($registered->id),
                        TextEntry::make('version')
                            ->label(__('phpinnacle-ferry::schema-registry.version'))
                            ->state($registered->version),
                        TextEntry::make('type')
                            ->label(__('phpinnacle-ferry::schema-registry.type'))
                            ->state($registered->type),
                        TextEntry::make('compatibility')
                            ->label(__('phpinnacle-ferry::schema-registry.compatibility'))
                            ->state($compatibility)
                            ->badge()
                            ->placeholder(__('phpinnacle-ferry::schema-registry.unavailable')),
                    ])
                    ->columns(4),
                Tabs::make('schema')
                    ->tabs([
                        Tab::make(__('phpinnacle-ferry::schema-registry.structure'))
                            ->schema([$this->preview($registered)]),
                        Tab::make(__('phpinnacle-ferry::schema-registry.source'))
                            ->schema([
                                CodeEntry::make('schema')
                                    ->hiddenLabel()
                                    ->state($registered->formattedSchema())
                                    ->grammar($registered->type === 'PROTOBUF' ? 'proto' : 'json')
                                    ->copyable()
                                    ->copyableState($registered->schema),
                            ]),
                        Tab::make(__('phpinnacle-ferry::schema-registry.references'))
                            ->visible($registered->references !== [])
                            ->schema([
                                RepeatableEntry::make('references')
                                    ->hiddenLabel()
                                    ->state($registered->references)
                                    ->schema([
                                        TextEntry::make('name')->label(__('phpinnacle-ferry::schema-registry.name')),
                                        TextEntry::make('subject')->label(__(
                                            'phpinnacle-ferry::schema-registry.subject',
                                        )),
                                        TextEntry::make('version')->label(__(
                                            'phpinnacle-ferry::schema-registry.version',
                                        )),
                                    ])
                                    ->columns(3),
                            ]),
                    ])
                    ->columnSpanFull(),
            ];
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            if ($exception instanceof RequestException && $exception->response->notFound()) {
                return [Callout::make(__('phpinnacle-ferry::schema-registry.version_not_found'))];
            }

            report($exception);

            return [Callout::make(__('phpinnacle-ferry::schema-registry.unavailable'))->danger()];
        }
    }

    private function preview(RegisteredSchema $registered): Component
    {
        try {
            $schema = match ($registered->type) {
                'AVRO' => $this->avro->convert(
                    $registered->references === []
                        ? $registered->schema
                        : ($this->resolvedAvroSchemas[$registered->version] ??= $this->registry->resolvedAvroSchema(
                            $registered->subject,
                            $registered->version,
                            $this->showDeleted,
                        )),
                ),
                'JSON' => JsonSchema::fromJson($registered->schema),
                default => throw new SchemaPreviewUnavailable,
            };
            $schema = $schema->resolveReferences();
        } catch (SchemaPreviewUnavailable|InvalidArgumentException) {
            return Callout::make(__('phpinnacle-ferry::schema-registry.preview_unavailable'))
                ->description(__('phpinnacle-ferry::schema-registry.use_source'));
        } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
            report($exception);

            return Callout::make(__('phpinnacle-ferry::schema-registry.preview_unavailable'))
                ->description(__('phpinnacle-ferry::schema-registry.use_source'));
        }

        return JsonSchemaEntry::make('structure')
            ->hiddenLabel()
            ->jsonSchema($schema)
            ->inline()
            ->searchable()
            ->format()
            ->constraints()
            ->requiredMarker();
    }
}
