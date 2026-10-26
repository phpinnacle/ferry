<?php

namespace PHPinnacle\Ferry\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use PHPinnacle\Ferry\Data\StructurePlan;
use PHPinnacle\Ferry\Enums\StructureStatus;
use PHPinnacle\Ferry\Jobs\Structure\PrepareStructureJob;
use PHPinnacle\Ferry\Observers\StructureObserver;

/**
 * @property string $upstream_id
 * @property StructureStatus $status
 * @property string|null $run_id
 * @property int $processed
 * @property int $estimated
 * @property int $generation
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Upstream $upstream
 */
#[ObservedBy(StructureObserver::class)]
class Structure extends Model
{
    use HasUuids;

    protected $table = 'structures';

    protected $primaryKey = 'upstream_id';

    protected $attributes = [
        'status' => StructureStatus::Pending->value,
        'generation' => 0,
        'run_id' => null,
        'processed' => 0,
        'estimated' => 0,
        'context' => null,
    ];

    protected $casts = [
        'status' => StructureStatus::class,
        'generation' => 'integer',
        'processed' => 'integer',
        'estimated' => 'integer',
        'context' => 'array',
    ];

    /** @return BelongsTo<Upstream, $this> */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class, 'upstream_id');
    }

    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options) {
            if ($this->exists) {
                Upstream::withTrashed()->whereKey($this->upstream_id)->lockForUpdate()->first();
            }

            return parent::save($options);
        });
    }

    public function canPrepare(int $staleAfter): bool
    {
        return (
            $this->upstream->adapter !== null
            && ($this->status !== StructureStatus::Preparing || $this->structureIsStale($staleAfter))
        );
    }

    public function wasPublished(): bool
    {
        return $this->wasChanged('generation');
    }

    public function draftRevision(): int
    {
        return $this->generation + 1;
    }

    /** @return Builder<self> */
    public static function run(string $upstreamId, ?string $runId): Builder
    {
        return self::query()
            ->whereKey($upstreamId)
            ->whereHas('upstream', fn (Builder $query) => $query->whereNotNull('adapter'))
            ->where('status', StructureStatus::Preparing)
            ->where('run_id', $runId);
    }

    public function fail(): bool
    {
        $failed = $this->updateCurrentRun([
            'status' => StructureStatus::Failed,
            'run_id' => null,
            'context' => null,
        ]);

        if (!$failed) {
            return false;
        }

        $this->discardDraftItems();

        return true;
    }

    /** @return HasMany<StructureItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StructureItem::class, 'upstream_id', 'upstream_id');
    }

    public function prepare(int $staleAfter): bool
    {
        return $this->getConnection()->transaction(function () use ($staleAfter) {
            $this->upstream()->lockForUpdate()->first();
            $this->refresh();

            if (!$this->canPrepare($staleAfter)) {
                return false;
            }

            $this->restart();

            return true;
        });
    }

    public function progressTo(int $processed): bool
    {
        return $this->updateCurrentRun([
            'processed' => $processed,
        ]);
    }

    /** @return Builder<StructureItem> */
    public function publishedItems(): Builder
    {
        return $this
            ->items()
            ->getQuery()
            ->where('revision', $this->generation)
            ->whereNull('parent_id')
            ->orderBy('position');
    }

    public function publishedItem(string $source): ?StructureItem
    {
        return $this->publishedItems()->where('external_id', $source)->first();
    }

    public function publish(): void
    {
        $revision = $this->draftRevision();

        $this->status = StructureStatus::Ready;
        $this->generation = $revision;
        $this->run_id = null;
        $this->context = null;
        $this->save();

        $this
            ->items()
            ->where('revision', '!=', $revision)
            ->delete();
    }

    private function beginStructureRun(): void
    {
        $this->status = $this->upstream->adapter === null ? StructureStatus::Pending : StructureStatus::Preparing;
        $this->run_id = $this->status === StructureStatus::Preparing ? $this->newUniqueId() : null;
        $this->processed = 0;
        $this->estimated = 0;
        $this->context = null;
    }

    private function discardDraftItems(): void
    {
        $this
            ->items()
            ->where('revision', '>', $this->generation)
            ->delete();
    }

    private function structureIsStale(int $staleAfter): bool
    {
        return $this->updated_at->lte(CarbonImmutable::now()->subSeconds($staleAfter));
    }

    /**
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateCurrentRun(array $attributes): bool
    {
        $currentRun = self::run($this->upstream_id, $this->run_id);

        $this->forceFill($attributes);

        $updated = $currentRun->update(
            array_intersect_key($this->getAttributes(), $attributes),
        );

        $this->refresh();

        return $updated === 1;
    }

    public function restart(): void
    {
        $this->getConnection()->transaction(function () {
            $this->upstream()->lockForUpdate()->first();
            $this->refresh();
            $this->discardDraftItems();
            $this->beginStructureRun();
            $this->save();
            if ($this->run_id !== null) {
                PrepareStructureJob::dispatch($this->upstream_id, $this->run_id)->afterCommit();
            }
        });
    }

    public function plan(StructurePlan $plan): bool
    {
        return $this->updateCurrentRun([
            'context' => $plan->context,
            'processed' => 0,
            'estimated' => $plan->estimated,
        ]);
    }

    public function readPlan(): ?StructurePlan
    {
        return $this->context === null ? null : new StructurePlan($this->estimated, $this->context);
    }

    protected static function booted(): void
    {
        static::creating(function (self $structure) {
            $structure->beginStructureRun();
        });
        static::created(function (self $structure) {
            if ($structure->run_id !== null) {
                PrepareStructureJob::dispatch($structure->upstream_id, $structure->run_id)->afterCommit();
            }
        });
    }

    /** @param list<StructureItem> $objects */
    public function importItems(array $objects, int $offset): bool
    {
        return $this->getConnection()->transaction(function () use ($objects, $offset) {
            $this->upstream()->lockForUpdate()->first();
            $current = self::run($this->upstream_id, $this->run_id)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }

            $externalIds = [];
            foreach ($objects as $object) {
                $externalIds[] = $object->external_id;
                foreach ($object->children as $child) {
                    $externalIds[] = $child->external_id;
                }
            }
            $revision = $current->draftRevision();
            /** @var array<string, string> $ids */
            $ids = $current
                ->items()
                ->where('revision', $revision)
                ->whereIn('external_id', $externalIds)
                ->pluck('id', 'external_id')
                ->all();
            foreach ($externalIds as $externalId) {
                $ids[$externalId] ??= new StructureItem()->newUniqueId();
            }

            $roots = [];
            $children = [];
            foreach ($objects as $position => $object) {
                $roots[] = $current->itemRow($object, $ids[$object->external_id], $offset + $position);
                foreach ($object->children as $position => $child) {
                    $children[] = $current->itemRow(
                        $child,
                        $ids[$child->external_id],
                        $position,
                        $ids[$object->external_id],
                    );
                }
            }
            $unique = ['upstream_id', 'external_id', 'revision'];
            $columns = [
                'parent_id',
                'reference_id',
                'name',
                'code',
                'kind',
                'label',
                'title',
                'key_column',
                'priority',
                'schema',
                'values',
                'position',
            ];
            StructureItem::query()->upsert($roots, $unique, $columns);
            StructureItem::query()->upsert($children, $unique, $columns);

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function itemRow(StructureItem $object, string $id, int $position, ?string $parentId = null): array
    {
        return [
            ...$object->getAttributes(),
            'id' => $id,
            'upstream_id' => $this->upstream_id,
            'parent_id' => $parentId,
            'position' => $position,
            'revision' => $this->draftRevision(),
        ];
    }
}
