<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\ConnectionMetadata;
use PHPinnacle\Ferry\Models\Connector;
use PHPinnacle\Ferry\Models\Sync;

return new class extends Migration {
    public function up(): void
    {
        /** @see Connection */
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('driver');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('database');
            $table->string('username');
            $table->text('password');
            $table->string('schema')->nullable();
            $table->string('ssl_mode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_passed')->nullable();
            $table->string('status')->index();
            $table->unsignedBigInteger('generation')->default(0);
            $table->uuid('run_id')->nullable();
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->json('storage_map')->nullable();
            $table->json('type_map')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();

            $this->addTenancy($table);
        });

        /** @see ConnectionMetadata */
        Schema::create('connection_metadata', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Connection::class, 'connection_id')
                ->constrained('connections')
                ->cascadeOnDelete();
            $table
                ->foreignIdFor(ConnectionMetadata::class, 'parent_id')
                ->index()
                ->nullable();
            $table->string('external_id');
            $table->string('reference_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('code');
            $table->string('kind')->index();
            $table->string('label');
            $table->string('title');
            $table->json('system');
            $table->json('properties');
            $table->json('values');
            $table->unsignedInteger('position');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();

            $table->unique(['connection_id', 'external_id', 'revision']);
            $table->index(['connection_id', 'revision']);

            $this->addTenancy($table);
        });

        /** @see Sync */
        Schema::create('syncs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Connection::class, 'connection_id')
                ->constrained('connections')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->index();
            $table->boolean('is_paused')->default(false);
            $table->string('static_destination')->nullable();
            $table->string('source');
            $table->string('destination');
            $table->json('schema');
            $table->timestamps();

            $this->addTenancy($table);
        });

        /** @see Connector */
        Schema::create('connectors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table
                ->foreignIdFor(Connection::class, 'connection_id')
                ->nullable()
                ->unique()
                ->constrained('connections')
                ->cascadeOnDelete();
            $table
                ->foreignIdFor(Sync::class, 'sync_id')
                ->nullable()
                ->unique()
                ->constrained('syncs')
                ->cascadeOnDelete();
            $table->string('name')->unique();
            $table->text('config')->nullable();
            $table->string('status')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $this->addTenancy($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectors');
        Schema::dropIfExists('syncs');
        Schema::dropIfExists('connection_metadata');
        Schema::dropIfExists('connections');
    }

    public function getConnection(): ?string
    {
        return config('phpinnacle-ferry.connection');
    }

    private function addTenancy(Blueprint $table): bool
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

            return true;
        }

        return false;
    }
};
