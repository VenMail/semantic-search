<?php

namespace Venmail\SemanticSearch\Adapters;

use Illuminate\Contracts\Cache\Repository as Cache;
use Venmail\SemanticSearch\Data\SearchResult;

class CacheAdapter
{
    private Cache $cache;
    private string $prefix;
    private int $defaultTTL;
    
    public function __construct(Cache $cache, string $prefix = 'semantic_search', int $defaultTTL = 3600)
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
        $this->defaultTTL = $defaultTTL;
    }
    
    public function get(string $key): ?SearchResult
    {
        $cached = $this->cache->get($this->prefix . ':' . $key);
        
        if ($cached instanceof SearchResult) {
            return $cached;
        }
        
        return null;
    }
    
    public function put(string $key, SearchResult $result, ?int $ttl = null): void
    {
        $this->cache->put(
            $this->prefix . ':' . $key,
            $result,
            $ttl ?? $this->defaultTTL
        );
    }
    
    public function forget(string $key): void
    {
        $this->cache->forget($this->prefix . ':' . $key);
    }
    
    public function flush(): void
    {
        // Note: This will flush all cache, not just semantic search
        // For production, consider a more targeted approach
        $this->cache->flush();
    }
    
    public function generateCacheKey(string $query, array $options = []): string
    {
        return md5($query . serialize($options));
    }
}



