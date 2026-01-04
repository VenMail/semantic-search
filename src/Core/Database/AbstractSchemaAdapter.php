<?php

namespace Venmail\SemanticSearch\Core\Database;

interface AbstractSchemaAdapter
{
    /**
     * Get all columns for a table with their types
     */
    public function getTableColumns(string $table): array;
    
    /**
     * Get indexes for a table
     */
    public function getTableIndexes(string $table): array;
    
    /**
     * Get primary key for a table
     */
    public function getPrimaryKey(string $table): ?string;
    
    /**
     * Get foreign keys for a table
     */
    public function getForeignKeys(string $table): array;
    
    /**
     * Normalize database-specific column type to generic type
     */
    public function normalizeColumnType(string $type): string;
    
    /**
     * Get database driver name
     */
    public function getDriverName(): string;
}

