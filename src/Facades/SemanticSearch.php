<?php

namespace Venmail\SemanticSearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Venmail\SemanticSearch\Core\SearchResult search(string $query, array $options = [])
 * @method static \Venmail\SemanticSearch\Core\PendingSemanticSearch query(string $query)
 * @method static void clearCache(?string $pattern = null)
 * @method static array getHistory(int $limit = 20)
 * @method static array getSuggestions(string $partialQuery, int $limit = 5)
 * @method static array generateSuggestions(string $query)
 * @method static array validateQueryStructure(array $queryStructure)
 * @method static array getQueryExplanation(string $query)
 * @method static array getMetrics()
 * @method static void resetMetrics()
 *
 * @see \Venmail\SemanticSearch\Core\EnhancedSemanticSearchService
 */
class SemanticSearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'semantic-search';
    }
}


