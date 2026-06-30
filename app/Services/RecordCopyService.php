<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RecordCopyService
{
    public function copy(
        Model $source,
        array $attributes = [],
        array $relationsToCopy = []
    ): Model {
        return DB::transaction(function () use ($source, $attributes, $relationsToCopy) {
            $copy = $source->replicate();

            $copy->forceFill($attributes);
            $copy->save();

            foreach ($relationsToCopy as $relationName => $config) {
                $this->copyRelation($source, $copy, $relationName, $config);
            }

            return $copy;
        });
    }

    protected function copyRelation(
        Model $source,
        Model $copy,
        string $relationName,
        array $config
    ): void {
        $foreignKey = $config['foreign_key'] ?? null;
        $attributes = $config['attributes'] ?? [];

        if (! $foreignKey) {
            return;
        }

        $source->loadMissing($relationName);

        foreach ($source->{$relationName} as $relatedRecord) {
            $newRelatedRecord = $relatedRecord->replicate();

            $newRelatedRecord->forceFill(array_merge(
                [
                    $foreignKey => $copy->getKey(),
                ],
                $attributes
            ));

            $newRelatedRecord->save();
        }
    }
}