<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SchemaAnalyzer
{
    private const CACHE_PREFIX = 'semantic_search:schema:';
    private const CACHE_TTL = 14400; // 4 hours

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
     * Get all columns for a table with their types
     */
    private function getTableColumns(string $table): array
    {
        $cacheKey = self::CACHE_PREFIX . 'columns:' . md5($table);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                $columns = DB::select("SHOW COLUMNS FROM `{$table}`");
                $result = [];
                
                foreach ($columns as $column) {
                    $column = (array)$column;
                    $result[$column['Field']] = [
                        'type' => $this->normalizeColumnType($column['Type'] ?? ''),
                        'nullable' => ($column['Null'] ?? '') === 'YES',
                        'default' => $column['Default'] ?? null,
                        'key' => $column['Key'] ?? '',
                    ];
                }
                
                return $result;
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
        $cacheKey = self::CACHE_PREFIX . 'indexes:' . md5($table);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                $indexes = DB::select("SHOW INDEXES FROM `{$table}`");
                $result = [];
                
                foreach ($indexes as $index) {
                    $index = (array)$index;
                    $indexName = $index['Key_name'] ?? '';
                    $columnName = $index['Column_name'] ?? '';
                    
                    if (!isset($result[$indexName])) {
                        $result[$indexName] = [
                            'name' => $indexName,
                            'type' => $index['Index_type'] ?? 'BTREE',
                            'unique' => (int)($index['Non_unique'] ?? 1) === 0,
                            'columns' => [],
                        ];
                    }
                    
                    $result[$indexName]['columns'][] = $columnName;
                }
                
                return $result;
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
        $cacheKey = self::CACHE_PREFIX . 'primary:' . md5($table);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                $indexes = $this->getTableIndexes($table);
                foreach ($indexes as $index) {
                    if ($index['name'] === 'PRIMARY') {
                        return $index['columns'][0] ?? null;
                    }
                }
                return null;
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
        $cacheKey = self::CACHE_PREFIX . 'foreign:' . md5($table);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($table) {
            try {
                $connection = DB::connection();
                $database = $connection->getDatabaseName();
                
                $foreignKeys = DB::select("
                    SELECT 
                        COLUMN_NAME,
                        REFERENCED_TABLE_NAME,
                        REFERENCED_COLUMN_NAME,
                        CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$database, $table]);
                
                $result = [];
                foreach ($foreignKeys as $fk) {
                    $fk = (array)$fk;
                    $result[$fk['COLUMN_NAME']] = [
                        'column' => $fk['COLUMN_NAME'],
                        'referenced_table' => $fk['REFERENCED_TABLE_NAME'],
                        'referenced_column' => $fk['REFERENCED_COLUMN_NAME'],
                        'constraint' => $fk['CONSTRAINT_NAME'],
                    ];
                }
                
                return $result;
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
     * Normalize MySQL column type to generic type
     */
    private function normalizeColumnType(string $type): string
    {
        $type = strtolower($type);
        
        if (str_contains($type, 'int')) {
            return 'integer';
        }
        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 'decimal';
        }
        if (str_contains($type, 'date') && !str_contains($type, 'time')) {
            return 'date';
        }
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return 'datetime';
        }
        if (str_contains($type, 'time') && !str_contains($type, 'date') && !str_contains($type, 'stamp')) {
            return 'time';
        }
        if (str_contains($type, 'text') || str_contains($type, 'varchar') || str_contains($type, 'char')) {
            return 'string';
        }
        if (str_contains($type, 'bool') || str_contains($type, 'tinyint(1)')) {
            return 'boolean';
        }
        if (str_contains($type, 'json')) {
            return 'json';
        }
        
        return 'string';
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


