<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Venmail\SemanticSearch\Data\Filter;
use Venmail\SemanticSearch\Data\ProjectMetadata;

class FieldResolver
{
    private SchemaAnalyzer $schemaAnalyzer;
    private ProjectMetadata $metadata;
    
    public function __construct(SchemaAnalyzer $schemaAnalyzer, ProjectMetadata $metadata)
    {
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->metadata = $metadata;
    }
    
    /**
     * Resolve field name to actual database column with intelligence
     */
    public function resolveField(Filter $filter, Model $model): ?ResolvedField
    {
        $modelClass = get_class($model);
        $requestedField = $filter->getField();
        
        // Try mapping first
        $mapping = $filter->getMapping();
        if ($mapping) {
            $parts = explode('.', $mapping);
            if (count($parts) === 2) {
                $resolved = $this->resolveFieldName($parts[1], $model);
                if ($resolved) {
                    return $resolved;
                }
            }
        }
        
        // Try direct field name
        $resolved = $this->resolveFieldName($requestedField, $model);
        if ($resolved) {
            return $resolved;
        }
        
        // Try variations
        $variations = $this->generateFieldVariations($requestedField);
        foreach ($variations as $variation) {
            $resolved = $this->resolveFieldName($variation, $model);
            if ($resolved) {
                return $resolved;
            }
        }
        
        // Try to find by type (e.g., if looking for "date", find date columns)
        $resolved = $this->resolveByType($requestedField, $model);
        if ($resolved) {
            return $resolved;
        }
        
        return null;
    }
    
    private function resolveFieldName(string $fieldName, Model $model): ?ResolvedField
    {
        $modelClass = get_class($model);
        $schema = $this->schemaAnalyzer->getModelSchema($modelClass);
        $columns = $schema['columns'] ?? [];
        
        // Exact match
        if (isset($columns[$fieldName])) {
            return new ResolvedField(
                field: $fieldName,
                type: $columns[$fieldName]['type'] ?? 'string',
                indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $fieldName),
                nullable: $columns[$fieldName]['nullable'] ?? false
            );
        }
        
        // Snake case match
        $snakeField = Str::snake($fieldName);
        if (isset($columns[$snakeField])) {
            return new ResolvedField(
                field: $snakeField,
                type: $columns[$snakeField]['type'] ?? 'string',
                indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $snakeField),
                nullable: $columns[$snakeField]['nullable'] ?? false
            );
        }
        
        // Check model fillable/attributes
        $fillable = $model->getFillable();
        if (in_array($fieldName, $fillable, true) || $model->hasAttribute($fieldName)) {
            // Field exists but might not be in schema (computed/accessor)
            return new ResolvedField(
                field: $fieldName,
                type: $this->inferTypeFromModel($model, $fieldName),
                indexed: false,
                nullable: true
            );
        }
        
        return null;
    }
    
    private function generateFieldVariations(string $fieldName): array
    {
        return [
            Str::snake($fieldName),
            Str::camel($fieldName),
            Str::kebab($fieldName),
            strtolower($fieldName),
            ucfirst($fieldName),
        ];
    }
    
    private function resolveByType(string $fieldName, Model $model): ?ResolvedField
    {
        $modelClass = get_class($model);
        $schema = $this->schemaAnalyzer->getModelSchema($modelClass);
        $columns = $schema['columns'] ?? [];
        
        // If field name suggests a type, find matching columns
        $fieldLower = strtolower($fieldName);
        
        // Date field lookup
        if (in_array($fieldLower, ['date', 'created', 'updated', 'deleted'])) {
            $dateColumns = [];
            foreach ($columns as $col => $info) {
                $type = $info['type'] ?? '';
                if (in_array($type, ['date', 'datetime', 'timestamp'], true) || 
                    str_contains($col, '_at') || str_contains($col, '_date')) {
                    $dateColumns[] = $col;
                }
            }
            
            if (!empty($dateColumns)) {
                // Prefer indexed date columns
                $bestColumn = $this->schemaAnalyzer->getBestFilterColumn($modelClass, $dateColumns);
                if ($bestColumn) {
                    return new ResolvedField(
                        field: $bestColumn,
                        type: $columns[$bestColumn]['type'] ?? 'date',
                        indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $bestColumn),
                        nullable: $columns[$bestColumn]['nullable'] ?? false
                    );
                }
            }
        }
        
        // Time field lookup
        if ($fieldLower === 'time') {
            foreach ($columns as $col => $info) {
                $type = $info['type'] ?? '';
                if ($type === 'time' || str_contains($col, '_time')) {
                    return new ResolvedField(
                        field: $col,
                        type: 'time',
                        indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $col),
                        nullable: $info['nullable'] ?? false
                    );
                }
            }
        }
        
        return null;
    }
    
    private function inferTypeFromModel(Model $model, string $field): string
    {
        $casts = $model->getCasts();
        if (isset($casts[$field])) {
            $cast = $casts[$field];
            if (in_array($cast, ['date', 'datetime', 'timestamp'], true)) {
                return 'datetime';
            }
            if (in_array($cast, ['int', 'integer'], true)) {
                return 'integer';
            }
            if (in_array($cast, ['float', 'double', 'decimal'], true)) {
                return 'decimal';
            }
            if ($cast === 'bool' || $cast === 'boolean') {
                return 'boolean';
            }
        }
        
        return 'string';
    }
    
    /**
     * Get best field for filtering from candidates
     */
    public function getBestFilterField(Model $model, array $candidateFields): ?ResolvedField
    {
        $modelClass = get_class($model);
        $schema = $this->schemaAnalyzer->getModelSchema($modelClass);
        $columns = $schema['columns'] ?? [];
        
        // Filter to only existing columns
        $existingFields = [];
        foreach ($candidateFields as $field) {
            if (isset($columns[$field]) || in_array($field, $model->getFillable(), true)) {
                $existingFields[] = $field;
            }
        }
        
        if (empty($existingFields)) {
            return null;
        }
        
        // Get best indexed column
        $bestColumn = $this->schemaAnalyzer->getBestFilterColumn($modelClass, $existingFields);
        if ($bestColumn) {
            return new ResolvedField(
                field: $bestColumn,
                type: $columns[$bestColumn]['type'] ?? 'string',
                indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $bestColumn),
                nullable: $columns[$bestColumn]['nullable'] ?? false
            );
        }
        
        // Fallback to first existing field
        $firstField = $existingFields[0];
        return new ResolvedField(
            field: $firstField,
            type: $columns[$firstField]['type'] ?? 'string',
            indexed: false,
            nullable: $columns[$firstField]['nullable'] ?? false
        );
    }
}

