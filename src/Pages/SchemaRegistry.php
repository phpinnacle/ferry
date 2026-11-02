<?php

namespace PHPinnacle\Ferry\Pages;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use PHPinnacle\Ferry\FerryPlugin;
use PHPinnacle\Ferry\Services\SchemaRegistryClient;
use UnexpectedValueException;

class SchemaRegistry extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'schema-registry';

    #[Url(as: 'deleted', except: false)]
    public bool $showDeleted = false;

    /** @var array<array-key, bool> */
    #[Locked]
    public array $subjectDeletionStates = [];

    private bool $unavailable = false;

    public static function canAccess(): bool
    {
        return FerryPlugin::get()->hasSchemaRegistry() && Gate::allows('view_schema_registry');
    }

    public static function getNavigationLabel(): string
    {
        return __('phpinnacle-ferry::schema-registry.label');
    }

    public static function getNavigationGroup(): string
    {
        return __('phpinnacle-ferry::resources.upstream.group');
    }

    public static function getNavigationIcon(): ?string
    {
        /** @var string|null */
        return config('phpinnacle-ferry.navigation.schema_registry.icon');
    }

    public static function getNavigationSort(): ?int
    {
        /** @var int|null */
        return config('phpinnacle-ferry.navigation.schema_registry.sort');
    }

    /** @return list<string> */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [static::getRouteName(), ViewSchemaRegistrySubject::getRouteName()];
    }

    public function getTitle(): string
    {
        return '';
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            static::getUrl($this->showDeleted ? ['deleted' => true] : []) => static::getNavigationLabel(),
            __('filament-panels::resources/pages/list-records.breadcrumb'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(static::getNavigationLabel())
            ->emptyStateIcon(static::getNavigationIcon())
            ->records(function (SchemaRegistryClient $registry, ?string $search, int $page, int $recordsPerPage) {
                try {
                    $schemas = $registry->schemas(
                        $search ?? '',
                        ($page - 1) * $recordsPerPage,
                        $recordsPerPage + 1,
                        $this->showDeleted,
                    );
                } catch (ConnectionException|RequestException|JsonException|UnexpectedValueException $exception) {
                    report($exception);
                    $this->unavailable = true;

                    return [];
                }

                $records = [];

                foreach ($schemas as $schema) {
                    $records[$schema->subject] = [
                        'subject' => $schema->subject,
                        'version' => $schema->version,
                        'type' => $schema->type,
                        'references_count' => count($schema->references),
                        'deleted' => $schema->deleted,
                    ];
                }

                return new Paginator($records, $recordsPerPage, $page);
            })
            ->columns([
                TextColumn::make('subject')
                    ->label(__('phpinnacle-ferry::schema-registry.subject'))
                    ->description(fn (array $record) => $record['deleted'] === true
                        ? __('phpinnacle-ferry::schema-registry.deleted')
                        : null)
                    ->searchable(),
                TextColumn::make('type')->label(__('phpinnacle-ferry::schema-registry.type')),
                TextColumn::make('references_count')
                    ->label(__('phpinnacle-ferry::schema-registry.references_count'))
                    ->badge()
                    ->alignCenter(),
                TextColumn::make('version')
                    ->label(__('phpinnacle-ferry::schema-registry.version'))
                    ->badge()
                    ->alignCenter(),
            ])
            ->searchPlaceholder(__('phpinnacle-ferry::schema-registry.search_prefix'))
            ->recordUrl($this->subjectUrl(...))
            ->recordActions([
                Action::make('view')
                    ->label(__('phpinnacle-ferry::schema-registry.view'))
                    ->icon('phosphor-eye')
                    ->iconButton()
                    ->color('gray')
                    ->url($this->subjectUrl(...)),
                $this->deleteSubjectAction(false),
                $this->deleteSubjectAction(true),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->deleteSubjectsAction(),
                ]),
            ])
            ->headerActions([
                Action::make('toggle_deleted')
                    ->label(fn () => __(
                        'phpinnacle-ferry::schema-registry.' . ($this->showDeleted ? 'hide_deleted' : 'show_deleted'),
                    ))
                    ->icon('phosphor-trash')
                    ->color(fn () => $this->showDeleted ? 'warning' : 'gray')
                    ->action(function () {
                        $this->showDeleted = !$this->showDeleted;
                        $this->resetTable();
                    }),
                Action::make('refresh')
                    ->label(__('phpinnacle-ferry::schema-registry.refresh'))
                    ->icon('phosphor-arrow-clockwise')
                    ->color('gray')
                    ->action($this->resetTable(...)),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(fn () => __(
                'phpinnacle-ferry::schema-registry.' . ($this->unavailable ? 'unavailable' : 'empty'),
            ))
            ->emptyStateDescription(fn () => __(
                'phpinnacle-ferry::schema-registry.' . ($this->unavailable ? 'retry' : 'empty_description'),
            ));
    }

    /** @param array{subject: string} $record */
    private function subjectUrl(array $record): string
    {
        return ViewSchemaRegistrySubject::getUrl([
            'subject' => $record['subject'],
            ...($this->showDeleted ? ['deleted' => true] : []),
        ]);
    }

    private function deleteSubjectAction(bool $permanent): Action
    {
        $ability = $permanent ? 'force_delete_schema_registry' : 'delete_schema_registry';
        $label = __('phpinnacle-ferry::schema-registry.' . ($permanent ? 'force_delete_subject' : 'delete_subject'));

        return Action::make($permanent ? 'force_delete_subject' : 'delete_subject')
            ->label($label)
            ->icon($permanent ? 'phosphor-trash-simple' : 'phosphor-trash')
            ->iconButton()
            ->color('danger')
            ->authorize(fn () => Gate::allows($ability))
            ->visible($this->canDeleteSubject(...))
            ->requiresConfirmation()
            ->modalHeading($this->subjectDeletionHeading(...))
            ->modalDescription(__(
                'phpinnacle-ferry::schema-registry.'
                . ($permanent ? 'force_delete_subject_description' : 'delete_subject_description'),
            ))
            ->successNotificationTitle(__('phpinnacle-ferry::schema-registry.subject_deleted'))
            ->failureNotificationTitle(__('phpinnacle-ferry::schema-registry.delete_failed'))
            ->failureNotificationBody(__('phpinnacle-ferry::schema-registry.delete_failed_description'))
            ->action($this->deleteSubject(...));
    }

    /** @param array{deleted: bool|null} $record */
    private function canDeleteSubject(array $record, Action $action): bool
    {
        return ($record['deleted'] === true) === ($action->getName() === 'force_delete_subject');
    }

    private function deleteSubjectsAction(): BulkAction
    {
        return BulkAction::make('delete_subjects')
            ->label(__('phpinnacle-ferry::schema-registry.delete_subjects'))
            ->icon('phosphor-trash')
            ->color('danger')
            ->authorize(fn () => Gate::allows('delete_schema_registry') || Gate::allows('force_delete_schema_registry'))
            ->requiresConfirmation()
            ->mountUsing($this->prepareSubjectDeletion(...))
            ->modalDescription(fn () => __('phpinnacle-ferry::schema-registry.delete_subjects_description', [
                'soft' => count(array_filter($this->subjectDeletionStates, fn (bool $deleted) => !$deleted)),
                'permanent' => count(array_filter($this->subjectDeletionStates)),
            ]))
            ->successNotificationTitle(__('phpinnacle-ferry::schema-registry.subjects_deleted'))
            ->failureNotificationTitle(fn (
                int $successCount,
                int $totalCount,
            ) => __('phpinnacle-ferry::schema-registry.subjects_deleted_partial', [
                'success' => $successCount,
                'total' => $totalCount,
            ]))
            ->failureNotificationBody(fn (BulkAction $action) => implode(
                ' ',
                $action->getBulkProcessingFailureMessages(),
            ))
            ->deselectRecordsAfterCompletion()
            ->action($this->deleteSubjects(...));
    }

    /** @param Collection<array-key, array{subject: string, deleted: bool|null}> $records */
    private function prepareSubjectDeletion(Collection $records): void
    {
        $this->subjectDeletionStates = [];

        foreach ($records as $record) {
            $this->subjectDeletionStates[$record['subject']] = $record['deleted'] === true;
        }
    }

    /** @param Collection<array-key, array{subject: string, deleted: bool|null}> $records */
    private function deleteSubjects(Collection $records, SchemaRegistryClient $registry, BulkAction $action): void
    {
        abort_unless(static::canAccess(), 403);

        foreach ($records as $record) {
            $permanent = $this->subjectDeletionStates[$record['subject']] ?? null;

            if (($record['deleted'] === true) !== $permanent) {
                $action->reportBulkProcessingFailure(
                    'state',
                    __('phpinnacle-ferry::schema-registry.delete_state_mismatch'),
                );

                continue;
            }

            if (Gate::denies($permanent ? 'force_delete_schema_registry' : 'delete_schema_registry')) {
                $action->reportBulkProcessingFailure(
                    'permission',
                    __('phpinnacle-ferry::schema-registry.delete_permission_denied'),
                );

                continue;
            }

            try {
                $registry->deleteSubject($record['subject'], $permanent);
            } catch (ConnectionException|RequestException $exception) {
                report($exception);
                $action->reportBulkProcessingFailure(
                    'registry',
                    __('phpinnacle-ferry::schema-registry.delete_failed_description'),
                );
            }
        }

        $this->resetTable();
    }

    /** @param array{subject: string} $record */
    private function subjectDeletionHeading(array $record, Action $action): string
    {
        return __('phpinnacle-ferry::schema-registry.' . $action->getName()) . ': ' . $record['subject'];
    }

    /** @param array{subject: string} $record */
    private function deleteSubject(array $record, SchemaRegistryClient $registry, Action $action): void
    {
        $permanent = $action->getName() === 'force_delete_subject';
        abort_unless(static::canAccess(), 403);
        Gate::authorize($permanent ? 'force_delete_schema_registry' : 'delete_schema_registry');

        try {
            $registry->deleteSubject($record['subject'], $permanent);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            $action->failure();

            return;
        }

        $action->success();
        $this->resetTable();
    }
}
