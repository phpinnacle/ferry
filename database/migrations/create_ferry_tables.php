<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Enums\DestinationType;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Replica;
use PHPinnacle\Ferry\Models\Structure;
use PHPinnacle\Ferry\Models\StructureItem;
use PHPinnacle\Ferry\Models\Upstream;

return new class extends Migration {
    public function up(): void
    {
        /** @see Connector */
        Schema::create('connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('origin');
            $table->uuid('upstream_id')->index();
            $table->text('config')->nullable();
            $table->text('applied_config')->nullable();
            $table->string('status')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $this->addTenancy($table);
        });

        /** @see Upstream */
        Schema::create('upstreams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Connector::class, 'connector_id')
                ->nullable()
                ->unique()
                ->constrained('connectors')
                ->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('origin');
            $table->string('adapter')->nullable();
            $table->string('driver');
            $table->text('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $this->addTenancy($table);
        });

        /** @see Structure */
        Schema::create('structures', function (Blueprint $table) {
            $table
                ->foreignIdFor(Upstream::class, 'upstream_id')
                ->primary()
                ->constrained('upstreams')
                ->cascadeOnDelete();
            $table->string('status')->index();
            $table->unsignedBigInteger('generation')->default(0);
            $table->uuid('run_id')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('estimated')->default(0);
            $table->json('context')->nullable();
            $table->timestamps();
        });

        /** @see StructureItem */
        Schema::create('structure_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Upstream::class, 'upstream_id')
                ->constrained('upstreams')
                ->cascadeOnDelete();
            $table->uuid('parent_id')->index()->nullable();
            $table->string('external_id');
            $table->string('reference_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('code')->nullable();
            $table->string('kind')->nullable()->index();
            $table->string('label');
            $table->string('title');
            $table->json('primary_key');
            $table->unsignedInteger('priority')->default(1);
            $table->json('schema');
            $table->json('values');
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['upstream_id', 'external_id', 'revision']);
            $table->index(['upstream_id', 'revision']);

            $this->addTenancy($table);
        });

        /** @see Replica */
        Schema::create('replicas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Connector::class, 'connector_id')
                ->nullable()
                ->unique()
                ->constrained('connectors')
                ->nullOnDelete();
            $table
                ->foreignIdFor(Upstream::class, 'upstream_id')
                ->constrained('upstreams')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->index();
            $table->string('type')->default(DestinationType::Dynamic->value);
            $table->string('source');
            $table->string('destination');
            $table->json('mapping');
            $table->json('schema');
            $table->json('transforms')->default('[]');
            $table->timestamps();
            $table->softDeletes();

            $this->addTenancy($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replicas');
        Schema::dropIfExists('structure_items');
        Schema::dropIfExists('structures');
        Schema::dropIfExists('upstreams');
        Schema::dropIfExists('connectors');
    }

    public function getConnection(): ?string
    {
        return config('phpinnacle-ferry.connection');
    }

    private function addTenancy(Blueprint $table): void
    {
        $tenancy = config('phpinnacle-ferry.tenancy');

        if (($tenancy['model'] ?? null) !== null && class_exists($tenancy['model'])) {
            $table
                ->foreignIdFor($tenancy['model'], 'tenant_id')
                ->after('id')
                ->index()
                ->default($tenancy['default'])
                ->constrained()
                ->cascadeOnDelete();
        }
    }
};
