<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Illuminate\Support\Facades\Cache;

class SlangMemoryService
{
    private const CACHE_KEY = 'semantic_search_slang_synonyms_v1';
    private const TTL_SECONDS = 86400; // 1 day

    private function store()
    {
        if (app()->runningUnitTests() || app()->environment('testing') || !class_exists('Redis')) {
            return Cache::store();
        }
        return Cache::store('redis');
    }

    /**
     * Get all dynamic slang mappings as [regex => replacement].
     */
    public function getAll(): array
    {
        $store = $this->store();
        return $store->get(self::CACHE_KEY, []);
    }

    /**
     * Apply slang mappings to a query string.
     */
    public function applyMappings(string $query): string
    {
        $mappings = $this->getAll();
        if (empty($mappings)) {
            return $query;
        }

        $normalized = $query;
        foreach ($mappings as $regex => $replacement) {
            $normalized = preg_replace($regex, $replacement, $normalized);
        }

        return trim(preg_replace('/\s+/', ' ', $normalized));
    }

    /**
     * Add or override a slang mapping.
     */
    public function addMapping(string $phrase, string $replacement): void
    {
        $store = $this->store();
        $map = $store->get(self::CACHE_KEY, []);
        $phrase = trim($phrase);
        if ($phrase === '') {
            return;
        }
        $escaped = preg_quote(mb_strtolower($phrase), '/');
        $regex = '/\\b' . $escaped . '\\b/i';
        $map[$regex] = ' ' . $replacement . ' ';
        $store->put(self::CACHE_KEY, $map, self::TTL_SECONDS);
    }

    /**
     * Remove a slang mapping by the original phrase.
     */
    public function removeMapping(string $phrase): void
    {
        $store = $this->store();
        $map = $store->get(self::CACHE_KEY, []);
        $phrase = trim($phrase);
        if ($phrase === '' || empty($map)) {
            return;
        }
        $escaped = preg_quote(mb_strtolower($phrase), '/');
        $regex = '/\\b' . $escaped . '\\b/i';
        if (isset($map[$regex])) {
            unset($map[$regex]);
            $store->put(self::CACHE_KEY, $map, self::TTL_SECONDS);
        }
    }
}

