<?php

namespace PHPinnacle\Ferry\Services;

use Illuminate\Database\Eloquent\Model;

class EloquentModels
{
    /**
     * @param list<class-string<Model>> $classes
     * @return array<string, list<class-string<Model>>>
     */
    public static function byTable(array $classes): array
    {
        $models = [];

        foreach ($classes as $class) {
            $model = new $class;
            $connection = $model->getConnection();
            [$schema, $table] = $connection->getSchemaBuilder()->parseSchemaAndTable($model->getTable(), true);
            $models[$schema . '.' . $connection->getTablePrefix() . $table][] = $class;
        }

        return $models;
    }
}
