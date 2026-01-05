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
    private array $fieldOverrides = [];
    private array $accessorFieldCache = [];
    private array $methodSourceCache = [];
    
    public function __construct(SchemaAnalyzer $schemaAnalyzer, ProjectMetadata $metadata)
    {
        $this->schemaAnalyzer = $schemaAnalyzer;
        $this->metadata = $metadata;
        $this->fieldOverrides = config('semantic-search.field_overrides', []);
    }
    
    /**
     * Resolve field name to actual database column with intelligence
     */
    public function resolveField(Filter $filter, Model $model): ?ResolvedField
    {
        $modelClass = get_class($model);
        $requestedField = $filter->getField();
        $overrideField = $this->getFieldOverride($modelClass, $requestedField);
        if ($overrideField) {
            $resolved = $this->resolveFieldName($overrideField, $model);
            if ($resolved) {
                return $resolved;
            }
        }
        
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

        // Try to infer accessor backing column
        $resolved = $this->resolveAccessorColumn($requestedField, $model);
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

    private function getFieldOverride(string $modelClass, string $field): ?string
    {
        if (empty($this->fieldOverrides[$modelClass])) {
            return null;
        }

        return $this->fieldOverrides[$modelClass][$field] ?? null;
    }

    private function resolveAccessorColumn(string $fieldName, Model $model): ?ResolvedField
    {
        $modelClass = get_class($model);
        $cacheKey = "{$modelClass}:{$fieldName}";
        if (array_key_exists($cacheKey, $this->accessorFieldCache)) {
            return $this->accessorFieldCache[$cacheKey];
        }

        $methodName = 'get' . Str::studly($fieldName) . 'Attribute';
        if (!method_exists($model, $methodName)) {
            return $this->accessorFieldCache[$cacheKey] = null;
        }

        try {
            $method = new \ReflectionMethod($model, $methodName);
        } catch (\ReflectionException $e) {
            return $this->accessorFieldCache[$cacheKey] = null;
        }

        $source = $this->getMethodSource($method);
        if ($source === null) {
            return $this->accessorFieldCache[$cacheKey] = null;
        }

        $schema = $this->schemaAnalyzer->getModelSchema($modelClass);
        $columns = $schema['columns'] ?? [];
        $candidates = $this->extractReturnColumnCandidates($source);

        foreach ($candidates as $candidate) {
            if (!isset($columns[$candidate])) {
                continue;
            }

            $resolved = new ResolvedField(
                field: $candidate,
                type: $columns[$candidate]['type'] ?? 'string',
                indexed: $this->schemaAnalyzer->isColumnIndexed($modelClass, $candidate),
                nullable: $columns[$candidate]['nullable'] ?? false
            );

            return $this->accessorFieldCache[$cacheKey] = $resolved;
        }

        return $this->accessorFieldCache[$cacheKey] = null;
    }

    private function getMethodSource(\ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if (!$file || !is_readable($file)) {
            return null;
        }

        if (!isset($this->methodSourceCache[$file])) {
            $this->methodSourceCache[$file] = file($file);
        }

        $lines = $this->methodSourceCache[$file];
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        $length = max(0, $end - $start + 1);

        if ($length === 0) {
            return null;
        }

        $snippet = array_slice($lines, $start - 1, $length);
        return implode('', $snippet);
    }

    private function extractReturnColumnCandidates(string $source): array
    {
        $candidates = [];

        if (preg_match_all('/return\s+(.*?);/s', $source, $returns)) {
            foreach ($returns[1] as $expr) {
                if (preg_match_all("/\$this->attributes\\[['\"]([A-Za-z0-9_]+)['\"]\\]/", $expr, $attrMatches)) {
                    foreach ($attrMatches[1] as $attr) {
                        $candidates[] = $attr;
                    }
                }

                if (preg_match_all('/\$this->([A-Za-z0-9_]+)/', $expr, $propMatches)) {
                    foreach ($propMatches[1] as $prop) {
                        $candidates[] = Str::snake($prop);
                    }
                }
            }
        }

        return array_values(array_unique($candidates));
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
        
        // If schema information is unavailable (e.g., database inspection failed), fall back to
        // model-provided attributes as a last resort to avoid hard failures.
        if (empty($columns)) {
            $fillable = $model->getFillable();
            if (in_array($fieldName, $fillable, true) || $model->hasAttribute($fieldName)) {
                return new ResolvedField(
                    field: $fieldName,
                    type: $this->inferTypeFromModel($model, $fieldName),
                    indexed: false,
                    nullable: true
                );
            }
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


