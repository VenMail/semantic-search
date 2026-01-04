<?php

namespace Venmail\SemanticSearch\Core\Database;

use Illuminate\Support\Facades\DB;

class MySQLSchemaAdapter implements AbstractSchemaAdapter
{
    public function getTableColumns(string $table): array
    {
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
    }
    
    public function getTableIndexes(string $table): array
    {
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
    }
    
    public function getPrimaryKey(string $table): ?string
    {
        $indexes = $this->getTableIndexes($table);
        foreach ($indexes as $index) {
            if ($index['name'] === 'PRIMARY') {
                return $index['columns'][0] ?? null;
            }
        }
        return null;
    }
    
    public function getForeignKeys(string $table): array
    {
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
    }
    
    public function normalizeColumnType(string $type): string
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
    
    public function getDriverName(): string
    {
        return 'mysql';
    }
}

