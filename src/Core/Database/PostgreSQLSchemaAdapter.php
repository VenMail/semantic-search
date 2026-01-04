<?php

namespace Venmail\SemanticSearch\Core\Database;

use Illuminate\Support\Facades\DB;

class PostgreSQLSchemaAdapter implements AbstractSchemaAdapter
{
    public function getTableColumns(string $table): array
    {
        $columns = DB::select("
            SELECT 
                column_name,
                data_type,
                is_nullable,
                column_default
            FROM information_schema.columns
            WHERE table_name = ?
            ORDER BY ordinal_position
        ", [$table]);
        
        $result = [];
        foreach ($columns as $column) {
            $column = (array)$column;
            $result[$column['column_name']] = [
                'type' => $this->normalizeColumnType($column['data_type'] ?? ''),
                'nullable' => ($column['is_nullable'] ?? '') === 'YES',
                'default' => $column['column_default'] ?? null,
                'key' => '',
            ];
        }
        
        return $result;
    }
    
    public function getTableIndexes(string $table): array
    {
        $indexes = DB::select("
            SELECT
                i.relname as index_name,
                a.attname as column_name,
                ix.indisunique as is_unique,
                am.amname as index_type
            FROM pg_class t
            JOIN pg_index ix ON t.oid = ix.indrelid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_am am ON i.relam = am.oid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
            WHERE t.relname = ?
            ORDER BY i.relname, a.attnum
        ", [$table]);
        
        $result = [];
        foreach ($indexes as $index) {
            $index = (array)$index;
            $indexName = $index['index_name'] ?? '';
            $columnName = $index['column_name'] ?? '';
            
            if (!isset($result[$indexName])) {
                $result[$indexName] = [
                    'name' => $indexName,
                    'type' => $index['index_type'] ?? 'btree',
                    'unique' => (bool)($index['is_unique'] ?? false),
                    'columns' => [],
                ];
            }
            
            $result[$indexName]['columns'][] = $columnName;
        }
        
        return $result;
    }
    
    public function getPrimaryKey(string $table): ?string
    {
        $pk = DB::select("
            SELECT a.attname
            FROM pg_index i
            JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
            WHERE i.indrelid = ?::regclass
            AND i.indisprimary
            LIMIT 1
        ", [$table]);
        
        if (!empty($pk)) {
            return ((array)$pk[0])['attname'] ?? null;
        }
        
        return null;
    }
    
    public function getForeignKeys(string $table): array
    {
        $foreignKeys = DB::select("
            SELECT
                kcu.column_name,
                ccu.table_name AS referenced_table_name,
                ccu.column_name AS referenced_column_name,
                tc.constraint_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
            AND tc.table_name = ?
        ", [$table]);
        
        $result = [];
        foreach ($foreignKeys as $fk) {
            $fk = (array)$fk;
            $result[$fk['column_name']] = [
                'column' => $fk['column_name'],
                'referenced_table' => $fk['referenced_table_name'],
                'referenced_column' => $fk['referenced_column_name'],
                'constraint' => $fk['constraint_name'],
            ];
        }
        
        return $result;
    }
    
    public function normalizeColumnType(string $type): string
    {
        $type = strtolower($type);
        
        if (in_array($type, ['integer', 'int', 'int4', 'int8', 'bigint', 'smallint'])) {
            return 'integer';
        }
        if (in_array($type, ['decimal', 'numeric', 'real', 'double precision', 'float', 'float4', 'float8'])) {
            return 'decimal';
        }
        if ($type === 'date') {
            return 'date';
        }
        if (in_array($type, ['timestamp', 'timestamptz', 'timestamp without time zone', 'timestamp with time zone'])) {
            return 'datetime';
        }
        if ($type === 'time') {
            return 'time';
        }
        if (in_array($type, ['text', 'varchar', 'character varying', 'char', 'character'])) {
            return 'string';
        }
        if (in_array($type, ['boolean', 'bool'])) {
            return 'boolean';
        }
        if ($type === 'json' || $type === 'jsonb') {
            return 'json';
        }
        
        return 'string';
    }
    
    public function getDriverName(): string
    {
        return 'pgsql';
    }
}

