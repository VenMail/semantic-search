<?php

namespace Venmail\SemanticSearch\Core\Database;

use Illuminate\Support\Facades\DB;

class SchemaAdapterFactory
{
    public static function create(): AbstractSchemaAdapter
    {
        $driver = DB::getDriverName();
        
        switch ($driver) {
            case 'mysql':
                return new MySQLSchemaAdapter();
            case 'pgsql':
                return new PostgreSQLSchemaAdapter();
            case 'sqlite':
                return new SQLiteSchemaAdapter();
            default:
                throw new \RuntimeException("Unsupported database driver: {$driver}. Supported drivers: mysql, pgsql, sqlite");
        }
    }
}

