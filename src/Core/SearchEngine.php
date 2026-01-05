<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Venmail\SemanticSearch\Adapters\CacheAdapter;
use Venmail\SemanticSearch\Core\LocaleManager;
use Venmail\SemanticSearch\Core\PendingSemanticSearch;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\ProjectMetadata;
use Venmail\SemanticSearch\Data\SearchResult;
use Venmail\SemanticSearch\History\QueryHistoryService;
use Venmail\SemanticSearch\Parsing\MultilingualQueryParser;
use Venmail\SemanticSearch\Security\PolicyGate;

class SearchEngine
{
    private ProjectAnalyzer $analyzer;
    private ContextualQueryBuilder $queryBuilder;
    private DynamicVocabularyBuilder $vocabularyBuilder;
    private CacheAdapter $cache;
    private Cache $cacheRepository;
    private PolicyGate $policyGate;
    private QueryHistoryService $historyService;
    private LocaleManager $localeManager;
    private ?ProjectMetadata $metadata = null;
    private ?Vocabulary $vocabulary = null;
    
    public function __construct(
        ProjectAnalyzer $analyzer,
        ContextualQueryBuilder $queryBuilder,
        DynamicVocabularyBuilder $vocabularyBuilder,
        CacheAdapter $cache,
        Cache $cacheRepository,
        PolicyGate $policyGate,
        QueryHistoryService $historyService,
        LocaleManager $localeManager
    ) {
        $this->analyzer = $analyzer;
        $this->queryBuilder = $queryBuilder;
        $this->vocabularyBuilder = $vocabularyBuilder;
        $this->cache = $cache;
        $this->cacheRepository = $cacheRepository;
        $this->policyGate = $policyGate;
        $this->historyService = $historyService;
        $this->localeManager = $localeManager;
    }

    public function query(string $query): PendingSemanticSearch
    {
        return new PendingSemanticSearch($this, $query);
    }
    
    public function search(string $query, array $options = []): SearchResult
    {
        $startTime = microtime(true);
        
        try {
            Log::info('[SemanticSearch] Starting search', [
                'query' => $query,
                'options' => $options
            ]);
        } catch (\Throwable $e) {
            // Ignore logging errors
        }
        
        // Check cache first
        if (config('semantic-search.cache.enabled', true)) {
            $cacheKey = $this->cache->generateCacheKey($query, $options);
            $cached = $this->cache->get($cacheKey);
            
            if ($cached) {
                return $cached;
            }
        }
        
        // Get or build metadata
        $metadata = $this->getMetadata();
        
        // Get or build vocabulary
        $vocabulary = $this->getVocabulary($metadata);
        
        // Apply disambiguation if enabled
        $disambiguationResult = null;
        if (config('semantic-search.disambiguation.enabled', true)) {
            $disambiguationPipeline = app(\Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline::class);
            $disambiguationResult = $disambiguationPipeline->process($query);
            $query = $disambiguationResult->getCorrectedQuery();
            
            // If disambiguation indicates clarification needed, return early
            if ($disambiguationResult->needsClarification()) {
                return new SearchResult(
                    data: [],
                    metadata: [
                        'type' => 'clarification',
                        'message' => 'I can clarify your request. Please confirm one of the options.',
                        'suggestions' => $disambiguationResult->getSuggestions(),
                        'confidence' => $disambiguationResult->getConfidence(),
                        'original_query' => $query,
                    ],
                    executionTime: microtime(true) - $startTime
                );
            }
            
            // If not parseable, return with suggestions
            if (!$disambiguationResult->isParseable()) {
                return new SearchResult(
                    data: [],
                    metadata: [
                        'error' => 'Query could not be parsed',
                        'reason' => $disambiguationResult->getFailureReason(),
                        'suggestions' => $disambiguationResult->getSuggestions(),
                        'original_query' => $query,
                    ],
                    executionTime: microtime(true) - $startTime
                );
            }
        }
        
        // Parse query using multilingual parser or LLM result
        $locale = $options['locale'] ?? $this->localeManager->getCurrentLocale();
        if (!$this->localeManager->isSupported($locale)) {
            $locale = $this->localeManager->getDefaultLocale();
        }
        
        $parsedQuery = null;
        
        // If LLM provided parsed data, use it
        if ($disambiguationResult && $disambiguationResult->getSource() === 'llm_fallback') {
            $llmData = $disambiguationResult->getLlmParsedData();
            if ($llmData && !empty($llmData)) {
                $llmAdapter = new \Venmail\SemanticSearch\Disambiguation\LLMFallbackAdapter($metadata);
                $parsedQuery = $llmAdapter->parseToParsedQuery($query, $llmData);
            }
        }
        
        // Fallback to standard parser
        if (!$parsedQuery) {
            try {
                Log::info('[SemanticSearch] Parsing query with MultilingualQueryParser');
                $parser = new MultilingualQueryParser($this->localeManager, $vocabulary);
                $parsedQuery = $parser->parse($query, $locale);
                Log::info('[SemanticSearch] Query parsed successfully', [
                    'entities' => count($parsedQuery->getEntities()),
                    'filters' => count($parsedQuery->getFilters())
                ]);
            } catch (\Throwable $e) {
                Log::error('[SemanticSearch] Parsing failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            // Enhance parsed query with disambiguation
            if ($disambiguationResult && config('semantic-search.disambiguation.enabled', true)) {
                $disambiguationPipeline = app(\Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline::class);
                $parsedQuery = $disambiguationPipeline->enhanceParsedQuery($parsedQuery);
            }
        }
        
        // Validate early so we can return structured error responses
        try {
            $this->validateQuery($parsedQuery);
        } catch (\InvalidArgumentException $e) {
            return new SearchResult(
                data: [],
                metadata: [
                    'error' => 'Invalid query',
                    'message' => $e->getMessage(),
                    'query' => $query,
                    'suggestions' => $this->generateSuggestions($query),
                ],
                executionTime: microtime(true) - $startTime
            );
        }
        
        // Build, authorize, and execute query
        try {
            
            // Authorize query (security check)
            $user = function_exists('auth') && auth()->check() ? auth()->user() : null;
            $this->policyGate->authorize($parsedQuery, $metadata, $user);
            
            Log::info('[SemanticSearch] Building query from parsed query', [
                'entities' => count($parsedQuery->getEntities()),
                'filters' => count($parsedQuery->getFilters())
            ]);
            
            $dbQuery = $this->queryBuilder->buildQuery($parsedQuery, $metadata);
            
            Log::info('[SemanticSearch] Query built successfully, executing', [
                'table_overrides' => $options['tables'] ?? null
            ]);

            if (!empty($options['tables']) && is_array($options['tables'])) {
                $result = $this->executeAcrossTables($dbQuery, $options);
            } else {
                $result = $this->executeQuery($dbQuery, $options);
            }
            
            $executionTime = microtime(true) - $startTime;
            $result->executionTime = $executionTime;
            
            // Store in query history
            if (config('semantic-search.history.enabled', true)) {
                $resultType = $result->getMetadata()['type'] ?? 'list';
                $user = function_exists('auth') && auth()->check() ? auth()->user() : null;
                $this->historyService->store($query, $user, $resultType);
            }
            
            // Cache result
            if (config('semantic-search.cache.enabled', true)) {
                $this->cache->put($cacheKey ?? $this->cache->generateCacheKey($query, $options), $result);
            }
            
            return $result;
        } catch (\InvalidArgumentException $e) {
            return new SearchResult(
                data: [],
                metadata: [
                    'error' => 'Invalid query',
                    'message' => $e->getMessage(),
                    'query' => $query,
                    'suggestions' => $this->generateSuggestions($query),
                ],
                executionTime: microtime(true) - $startTime
            );
        } catch (\RuntimeException $e) {
            return new SearchResult(
                data: [],
                metadata: [
                    'error' => 'Query execution failed',
                    'message' => $e->getMessage(),
                    'query' => $query,
                ],
                executionTime: microtime(true) - $startTime
            );
        } catch (\Exception $e) {
            return new SearchResult(
                data: [],
                metadata: [
                    'error' => 'Unexpected error',
                    'message' => $e->getMessage(),
                    'query' => $query,
                ],
                executionTime: microtime(true) - $startTime
            );
        }
    }

    private function executeAcrossTables(Builder $query, array $options): SearchResult
    {
        $tables = array_values(array_filter($options['tables'] ?? [], function ($table) {
            return is_string($table) && trim($table) !== '';
        }));

        if (empty($tables)) {
            return $this->executeQuery($query, $options);
        }

        $limit = $options['limit'] ?? null;
        $offset = max(0, (int)($options['offset'] ?? 0));
        $collected = [];
        $targetCount = $limit !== null ? $limit + $offset : null;

        foreach ($tables as $table) {
            $tableQuery = clone $query;
            $tableQuery->from($table);

            if ($targetCount !== null) {
                $remaining = $targetCount - count($collected);
                if ($remaining <= 0) {
                    break;
                }
                $tableQuery->limit($remaining);
            }

            $rows = $tableQuery->get()->toArray();

            foreach ($rows as $row) {
                $collected[] = $row;

                if ($targetCount !== null && count($collected) >= $targetCount) {
                    break 2;
                }
            }
        }

        if ($offset > 0) {
            $collected = array_slice($collected, $offset);
        }

        if ($limit !== null) {
            $collected = array_slice($collected, 0, $limit);
        }

        return new SearchResult(
            data: $collected,
            metadata: [
                'tables' => $tables,
            ]
        );
    }
    
    private function validateQuery(ParsedQuery $parsedQuery): void
    {
        if (!$parsedQuery->hasEntities()) {
            throw new \InvalidArgumentException(
                'Query must contain at least one entity (model name). ' .
                'Example: "show users" or "list orders"'
            );
        }
    }
    
    private function generateSuggestions(string $query): array
    {
        $suggestions = [];
        
        // Basic suggestions
        $suggestions[] = 'Try using model names like "users", "orders", "products"';
        $suggestions[] = 'Use filters like "older than 25" or "amount > 1000"';
        $suggestions[] = 'Example: "active users older than 25"';
        
        return $suggestions;
    }
    
    private function getMetadata(): ProjectMetadata
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }
        
        $cacheKey = 'semantic-search:metadata:' . md5(config_path('app.php'));
        
        if (config('semantic-search.cache.enabled', true)) {
            $cached = $this->cacheRepository->get($cacheKey);
            
            if ($cached instanceof ProjectMetadata) {
                // Check if files have changed (incremental analysis)
                if (!$this->hasChangedFiles($cached)) {
                    $this->metadata = $cached;
                    return $this->metadata;
                }
            }
        }
        
        // Analyze project (full or incremental)
        $this->metadata = $this->analyzer->analyze();
        
        // Cache metadata
        if (config('semantic-search.cache.enabled', true)) {
            $this->cacheRepository->put($cacheKey, $this->metadata, 3600);
        }
        
        return $this->metadata;
    }
    
    private function hasChangedFiles(ProjectMetadata $cachedMetadata): bool
    {
        // Quick check: if no file hashes stored, assume changed
        $cachedHashes = $cachedMetadata->getFileHashes();
        if (empty($cachedHashes)) {
            return true;
        }
        
        // Check if any tracked files have changed
        // This is a simplified check - full implementation would scan directories
        foreach ($cachedHashes as $path => $data) {
            if (!file_exists($path)) {
                return true; // File deleted
            }
            
            $currentMtime = filemtime($path);
            if ($currentMtime !== $data['mtime']) {
                return true; // File modified
            }
        }
        
        return false;
    }
    
    private function getVocabulary(ProjectMetadata $metadata): Vocabulary
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }
        
        $cacheKey = 'semantic-search:vocabulary:' . md5(serialize($metadata->toArray()));
        
        if (config('semantic-search.cache.enabled', true)) {
            $cached = $this->cacheRepository->get($cacheKey);
            
            if ($cached instanceof Vocabulary) {
                $this->vocabulary = $cached;
                return $this->vocabulary;
            }
        }
        
        // Build vocabulary
        $this->vocabulary = $this->vocabularyBuilder->buildVocabulary($metadata);
        
        // Cache vocabulary
        if (config('semantic-search.cache.enabled', true)) {
            $this->cacheRepository->put($cacheKey, $this->vocabulary, 3600);
        }
        
        return $this->vocabulary;
    }
    
    private function executeQuery(Builder $query, array $options): SearchResult
    {
        // Set query timeout
        $maxQueryTime = config('semantic-search.performance.max_query_time', 30);
        
        // Apply memory limit if configured
        $memoryLimit = config('semantic-search.performance.memory_limit');
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }
        
        // Set database query timeout (database-specific)
        $driver = DB::getDriverName();
        try {
            switch ($driver) {
                case 'mysql':
                    DB::statement("SET SESSION max_execution_time = {$maxQueryTime}");
                    break;
                case 'pgsql':
                    DB::statement("SET statement_timeout = {$maxQueryTime}000"); // PostgreSQL uses milliseconds
                    break;
                case 'sqlite':
                    // SQLite doesn't support query timeout at connection level
                    break;
                default:
                    // Unknown driver, skip timeout setting
                    break;
            }
        } catch (\Throwable $e) {
            // Ignore timeout setting errors - query will still execute
        }
        
        try {
            // Apply pagination
            if (isset($options['paginate'])) {
                $perPage = is_int($options['paginate']) ? $options['paginate'] : 15;
                
                $paginator = $query->paginate($perPage);
                
                return new SearchResult(
                    data: $paginator->items(),
                    pagination: [
                        'current_page' => $paginator->currentPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                        'last_page' => $paginator->lastPage(),
                    ]
                );
            }
            
            // Apply limits
            if (isset($options['limit'])) {
                $query->limit($options['limit']);
            }
            
            $data = $query->get()->toArray();
            
            return new SearchResult(
                data: $data
            );
        } catch (\Exception $e) {
            // Check if it's a timeout error
            if (str_contains($e->getMessage(), 'timeout') || 
                str_contains($e->getMessage(), 'max_execution_time')) {
                throw new \RuntimeException(
                    "Query execution exceeded maximum time limit of {$maxQueryTime} seconds. " .
                    "Please refine your query or increase the timeout in configuration."
                );
            }
            
            throw $e;
        }
    }
    
    public function clearCache(?string $pattern = null): void
    {
        if ($pattern) {
            // Clear specific pattern (would need custom cache implementation)
            $this->cache->flush();
        } else {
            $this->cache->flush();
        }
    }
    
    public function getHistory(int $limit = 20): array
    {
        $user = function_exists('auth') && auth()->check() ? auth()->user() : null;
        return $this->historyService->getHistory($user, $limit);
    }
    
    public function getSuggestions(string $partialQuery, int|array $limit = 5, array $options = []): array
    {
        if (is_array($limit)) {
            $options = $limit;
            $limit = 5;
        }
        
        $user = function_exists('auth') && auth()->check() ? auth()->user() : null;
        return $this->historyService->getSuggestions($partialQuery, $user, $limit, $options);
    }
}

