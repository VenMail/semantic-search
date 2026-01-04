<?php

namespace Venmail\SemanticSearch\History;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class QueryHistoryService
{
    private const CACHE_PREFIX = 'semantic_search_history:';
    private const MAX_ENTRIES = 50;
    private const TTL_SECONDS = 2592000; // 30 days

    /**
     * Store a query in user's history
     */
    public function store(string $query, ?Authenticatable $user = null, ?string $resultType = null): void
    {
        if (!$user) {
            return;
        }

        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return;
        }

        $key = self::CACHE_PREFIX . $user->id;
        $history = Cache::get($key, []);

        // Add new entry
        $history[] = [
            'query' => $query,
            'result_type' => $resultType,
            'timestamp' => now()->toIso8601String(),
        ];

        // Keep only last MAX_ENTRIES
        $history = array_slice($history, -self::MAX_ENTRIES);

        Cache::put($key, $history, self::TTL_SECONDS);
    }

    /**
     * Get user's query history
     */
    public function getHistory(?Authenticatable $user = null, int $limit = 20): array
    {
        if (!$user) {
            return [];
        }

        $key = self::CACHE_PREFIX . $user->id;
        $history = Cache::get($key, []);

        return array_slice(array_reverse($history), 0, $limit);
    }

    /**
     * Get query suggestions based on partial query
     */
    public function getSuggestions(string $partialQuery, ?Authenticatable $user = null, int $limit = 5): array
    {
        if (!$user || mb_strlen($partialQuery) < 2) {
            return [];
        }

        $key = self::CACHE_PREFIX . $user->id;
        $history = Cache::get($key, []);

        $partialLower = mb_strtolower($partialQuery);
        $suggestions = [];

        foreach ($history as $entry) {
            $query = mb_strtolower($entry['query'] ?? '');
            if (mb_strpos($query, $partialLower) !== false) {
                $suggestions[] = $entry['query'];
            }
        }

        // Remove duplicates and limit
        $suggestions = array_values(array_unique($suggestions));
        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Clear user's query history
     */
    public function clearHistory(?Authenticatable $user = null): void
    {
        if (!$user) {
            return;
        }

        $key = self::CACHE_PREFIX . $user->id;
        Cache::forget($key);
    }
}


