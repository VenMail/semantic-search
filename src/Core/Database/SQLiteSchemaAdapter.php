<?php

namespace Venmail\SemanticSearch\Core\Database;

use Illuminate\Support\Facades\DB;

class SQLiteSchemaAdapter implements AbstractSchemaAdapter
{
    public function getTableColumns(string $table): array
    {
        $columns = DB::select("PRAGMA table_info({$table})");
        $result = [];
        
        foreach ($columns as $column) {
            $column = (array)$column;
            $result[$column['name']] = [
                'type' => $this->normalizeColumnType($column['type'] ?? ''),
                'nullable' => (int)($column['notnull'] ?? 1) === 0,
                'default' => $column['dflt_value'] ?? null,
                'key' => (int)($column['pk'] ?? 0) === 1 ? 'PRI' : '',
            ];
        }
        
        return $result;
    }
    
    public function getTableIndexes(string $table): array
    {
        $indexes = DB::select("PRAGMA index_list({$table})");
        $result = [];
        
        foreach ($indexes as $index) {
            $index = (array)$index;
            $indexName = $index['name'] ?? '';
            
            // Skip auto-created indexes
            if (str_starts_with($indexName, 'sqlite_autoindex_')) {
                continue;
            }
            
            // Get columns for this index
            $indexInfo = DB::select("PRAGMA index_info({$indexName})");
            $columns = [];
            foreach ($indexInfo as $info) {
                $info = (array)$info;
                $columns[] = $info['name'] ?? '';
            }
            
            $result[$indexName] = [
                'name' => $indexName,
                'type' => 'btree',
                'unique' => (int)($index['unique'] ?? 0) === 1,
                'columns' => $columns,
            ];
        }
        
        return $result;
    }
    
    public function getPrimaryKey(string $table): ?string
    {
        $columns = $this->getTableColumns($table);
        foreach ($columns as $name => $info) {
            if ($info['key'] === 'PRI') {
                return $name;
            }
        }
        return null;
    }
    
    public function getForeignKeys(string $table): array
    {
        $foreignKeys = DB::select("PRAGMA foreign_key_list({$table})");
        $result = [];
        
        foreach ($foreignKeys as $fk) {
            $fk = (array)$fk;
            $columnName = $fk['from'] ?? '';
            
            $result[$columnName] = [
                'column' => $columnName,
                'referenced_table' => $fk['table'] ?? '',
                'referenced_column' => $fk['to'] ?? '',
                'constraint' => '',
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
        if (str_contains($type, 'real') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric')) {
            return 'decimal';
        }
        if ($type === 'date') {
            return 'date';
        }
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return 'datetime';
        }
        if ($type === 'time') {
            return 'time';
        }
        if (str_contains($type, 'text') || str_contains($type, 'varchar') || str_contains($type, 'char')) {
            return 'string';
        }
        if (str_contains($type, 'bool')) {
            return 'boolean';
        }
        if ($type === 'json') {
            return 'json';
        }
        
        return 'string';
    }
    
    public function getDriverName(): string
    {
        return 'sqlite';
    }
}

