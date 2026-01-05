<?php

namespace Venmail\SemanticSearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Venmail\SemanticSearch\Data\SearchResult search(string $query, array $options = [])
 * @method static \Venmail\SemanticSearch\Core\PendingSemanticSearch query(string $query)
 * @method static void clearCache(?string $pattern = null)
 * @method static array getHistory(int $limit = 20)
 * @method static array getSuggestions(string $partialQuery, int $limit = 5)
 *
 * @see \Venmail\SemanticSearch\Core\SearchEngine
 */
class SemanticSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'semantic-search';
    }
}


