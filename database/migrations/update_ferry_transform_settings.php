<?php

use Illuminate\Database\Migrations\Migration;
use PHPinnacle\Ferry\Models\Sync;

return new class extends Migration {
    public function up(): void
    {
        $this->convert('key', 'params', 'type', 'data');
    }

    public function down(): void
    {
        $this->convert('type', 'data', 'key', 'params');
    }

    private function convert(string $oldType, string $oldData, string $newType, string $newData): void
    {
        $connection = new Sync()->getConnection();
        $connection->transaction(function () use ($connection, $oldType, $oldData, $newType, $newData) {
            $connection
                ->table('syncs')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($connection, $oldType, $oldData, $newType, $newData) {
                    foreach ($rows as $row) {
                        $convert = static fn (array $settings) => array_map(
                            static fn (array $setting) => [
                                $newType => $setting[$oldType],
                                $newData => $setting[$oldData] ?? [],
                            ],
                            $settings,
                        );
                        $schema = json_decode($row->schema, true, flags: JSON_THROW_ON_ERROR);
                        foreach ($schema as &$mapping) {
                            if (array_key_exists('transforms', $mapping)) {
                                $mapping['transforms'] = $convert($mapping['transforms']);
                            }
                        }
                        unset($mapping);
                        $connection
                            ->table('syncs')
                            ->where('id', $row->id)
                            ->update([
                                'schema' => json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                                'transforms' => json_encode(
                                    $convert(json_decode($row->transforms, true, flags: JSON_THROW_ON_ERROR)),
                                    JSON_THROW_ON_ERROR,
                                ),
                            ]);
                    }
                });
        });
    }
};
