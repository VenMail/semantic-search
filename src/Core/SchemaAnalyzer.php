<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Venmail\SemanticSearch\Core\Database\SchemaAdapterFactory;
use Venmail\SemanticSearch\Core\Database\AbstractSchemaAdapter;

class SchemaAnalyzer
{
    private const CACHE_PREFIX = 'semantic_search:schema:';
    private const CACHE_TTL = 14400; // 4 hours
    private ?AbstractSchemaAdapter $adapter = null;

    /**
     * Get schema information for a model including columns, types, and indexes
     */
    public function getModelSchema(string $modelClass): array
    {
        $cacheKey = self::CACHE_PREFIX . 'model:' . md5($modelClass);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($modelClass) {
            if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
                return [];
            }

            try {
                $model = new $modelClass();
                $table = $model->getTable();
                
                return [
                    'table' => $table,
                    'columns' => $this->getTableColumns($table),
                    'indexes' => $this->getTableIndexes($table),
                    'primary_key' => $this->getPrimaryKey($table),
                    'foreign_keys' => $this->getForeignKeys($table),
                ];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Get database adapter
     */
    private function getAdapter(): AbstractSchemaAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = SchemaAdapterFactory::create();
        }
        return $this->adapter;
    }
    
    /**
     * Get all columns for a table with their types
     */
    private function getTableColumns(string $table): array
    {
        $cacheKey = self::CACHE_PREFIX . 'columns:' . md5($table . DB::getDriverName());
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                return $this->getAdapter()->getTableColumns($table);
            } catch (\Throwable $e) {
                // Fallback: try to get from model if available
                return [];
            }
        });
    }

    /**
     * Get indexes for a table
     */
    private function getTableIndexes(string $table): array
    {
        $cacheKey = self::CACHE_PREFIX . 'indexes:' . md5($table . DB::getDriverName());
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                return $this->getAdapter()->getTableIndexes($table);
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Get primary key for a table
     */
    private function getPrimaryKey(string $table): ?string
    {
        $cacheKey = self::CACHE_PREFIX . 'primary:' . md5($table . DB::getDriverName());
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                return $this->getAdapter()->getPrimaryKey($table);
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Get foreign keys for a table
     */
    private function getForeignKeys(string $table): array
    {
        $cacheKey = self::CACHE_PREFIX . 'foreign:' . md5($table . DB::getDriverName());
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                return $this->getAdapter()->getForeignKeys($table);
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /**
     * Check if a column is indexed
     */
    public function isColumnIndexed(string $modelClass, string $column): bool
    {
        $schema = $this->getModelSchema($modelClass);
        $indexes = $schema['indexes'] ?? [];
        
        foreach ($indexes as $index) {
            if (in_array($column, $index['columns'] ?? [], true)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get best indexed column for filtering (prioritizes indexed columns)
     */
    public function getBestFilterColumn(string $modelClass, array $candidateColumns): ?string
    {
        $schema = $this->getModelSchema($modelClass);
        $indexes = $schema['indexes'] ?? [];
        $columns = $schema['columns'] ?? [];
        
        // Build index map
        $indexedColumns = [];
        foreach ($indexes as $index) {
            foreach ($index['columns'] ?? [] as $col) {
                $indexedColumns[$col] = [
                    'unique' => $index['unique'] ?? false,
                    'index_name' => $index['name'] ?? '',
                ];
            }
        }
        
        // Priority: unique indexes > regular indexes > non-indexed
        $prioritized = [];
        foreach ($candidateColumns as $col) {
            if (!isset($columns[$col])) {
                continue;
            }
            
            $priority = 0;
            if (isset($indexedColumns[$col])) {
                $priority = $indexedColumns[$col]['unique'] ? 3 : 2;
            } else {
                $priority = 1;
            }
            
            $prioritized[] = [
                'column' => $col,
                'priority' => $priority,
            ];
        }
        
        if (empty($prioritized)) {
            return null;
        }
        
        // Sort by priority (descending)
        usort($prioritized, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        return $prioritized[0]['column'];
    }

    /**
     * Get column type for a field
     */
    public function getColumnType(string $modelClass, string $column): ?string
    {
        $schema = $this->getModelSchema($modelClass);
        return $schema['columns'][$column]['type'] ?? null;
    }

    /**
     * Check if column is nullable
     */
    public function isColumnNullable(string $modelClass, string $column): bool
    {
        $schema = $this->getModelSchema($modelClass);
        return $schema['columns'][$column]['nullable'] ?? false;
    }

    /**
     * Normalize column type to generic type (delegates to adapter)
     */
    private function normalizeColumnType(string $type): string
    {
        return $this->getAdapter()->normalizeColumnType($type);
    }

    /**
     * Clear schema cache for a model
     */
    public function clearModelCache(string $modelClass): void
    {
        $cacheKey = self::CACHE_PREFIX . 'model:' . md5($modelClass);
        Cache::forget($cacheKey);
        
        try {
            $model = new $modelClass();
            $table = $model->getTable();
            
            Cache::forget(self::CACHE_PREFIX . 'columns:' . md5($table));
            Cache::forget(self::CACHE_PREFIX . 'indexes:' . md5($table));
            Cache::forget(self::CACHE_PREFIX . 'primary:' . md5($table));
            Cache::forget(self::CACHE_PREFIX . 'foreign:' . md5($table));
        } catch (\Throwable $e) {
            // Ignore
        }
    }
}


