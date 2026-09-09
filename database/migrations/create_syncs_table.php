<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPinnacle\Ferry\Models\Connection;
use PHPinnacle\Ferry\Models\Sync;

return new class extends Migration {
    public function up(): void
    {
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
            $table->string('sink_connector_status')->nullable();
            $table->text('sink_connector_error')->nullable();
            $table->timestamp('sink_connector_checked_at')->nullable();
            $table->timestamps();

            $this->addTenancy($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syncs');
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
