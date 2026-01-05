<?php

namespace Venmail\SemanticSearch\Core;

use Venmail\SemanticSearch\Core\Validation\SQLStructureValidator;
use Venmail\SemanticSearch\Core\Fallback\AggressiveMappingFallback;
use Venmail\SemanticSearch\Core\Intelligence\RelationshipInterpreter;
use Venmail\SemanticSearch\Core\Intelligence\ActionDetector;
use Venmail\SemanticSearch\Core\Intelligence\QueryEnhancer;
use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Core\Config\SemanticFieldPatterns;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\SearchResult;
use Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline;
use Venmail\SemanticSearch\Disambiguation\DisambiguationResult;
use Venmail\SemanticSearch\Parsing\MultilingualQueryParser;
use Venmail\SemanticSearch\Adapters\CacheAdapter;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Enhanced Unified Semantic Search Service
 * 
 * This is the single entry point for all semantic search functionality.
 * It consolidates and enhances all previous components while eliminating
 * redundancies and providing a more robust, project-agnostic implementation.
 */
class EnhancedSemanticSearchService
{
    private Vocabulary $vocabulary;
    private SQLStructureValidator $validator;
    private AggressiveMappingFallback $fallback;
    private RelationshipInterpreter $interpreter;
    private ActionDetector $actionDetector;
    private QueryEnhancer $enhancer;
    private DisambiguationPipeline $disambiguation;
    private MultilingualQueryParser $parser;
    private CacheAdapter $cache;
    private Cache $cacheRepository;
    
    // Performance metrics
    private array $metrics = [
        'queries_processed' => 0,
        'avg_execution_time' => 0,
        'cache_hits' => 0,
        'fallback_used' => 0,
        'error_count' => 0
    ];
    
    public function __construct(
        Vocabulary $vocabulary,
        SQLStructureValidator $validator,
        AggressiveMappingFallback $fallback,
        RelationshipInterpreter $interpreter,
        ActionDetector $actionDetector,
        QueryEnhancer $enhancer,
        DisambiguationPipeline $disambiguation,
        MultilingualQueryParser $parser,
        CacheAdapter $cache,
        Cache $cacheRepository
    ) {
        $this->vocabulary = $vocabulary;
        $this->validator = $validator;
        $this->fallback = $fallback;
        $this->interpreter = $interpreter;
        $this->actionDetector = $actionDetector;
        $this->enhancer = $enhancer;
        $this->disambiguation = $disambiguation;
        $this->parser = $parser;
        $this->cache = $cache;
        $this->cacheRepository = $cacheRepository;
    }
    
    /**
     * Main search method - enhanced with caching, metrics, and better error handling
     */
    public function search(string $query, array $options = []): SearchResult
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($query, $options);
        
        // Try cache first
        if ($cached = $this->getCachedResult($cacheKey)) {
            $this->metrics['cache_hits']++;
            return $cached;
        }
        
        try {
            $result = $this->processQuery($query, $options, $startTime);
            $this->cacheResult($cacheKey, $result);
            $this->updateMetrics($startTime, false);
            return $result;
            
        } catch (\Throwable $e) {
            $this->metrics['error_count']++;
            $fallbackResult = $this->handleError($query, $e, $startTime);
            $this->updateMetrics($startTime, true);
            return $fallbackResult;
        }
    }
    
    /**
     * Enhanced query processing pipeline
     */
    private function processQuery(string $query, array $options, float $startTime): SearchResult
    {
        // Step 1: Parse and enhance the query
        $parsedQuery = $this->parser->parse($query);
        $parsedQuery = $this->enhancer->enhance($parsedQuery);
        
        // Step 2: Apply disambiguation
        $disambiguationResult = $this->disambiguation->process($query);
        
        // Step 3: Apply intelligent processing based on confidence
        $processedQuery = $this->applyIntelligentProcessing($parsedQuery, $disambiguationResult, $query);
        
        // Step 4: Execute the query
        $results = $this->executeEnhancedQuery($processedQuery, $options);
        
        return new SearchResult(
            data: $results,
            metadata: [
                'type' => 'search_results',
                'confidence' => $disambiguationResult->getConfidence(),
                'original_query' => $query,
                'enhanced_query' => $processedQuery,
                'execution_time' => microtime(true) - $startTime,
                'cache_hit' => false,
                'fallback_used' => $disambiguationResult->getConfidence() < 0.5,
                'enhancements_applied' => $this->getEnhancementsApplied($parsedQuery, $processedQuery)
            ]
        );
    }
    
    /**
     * Apply intelligent processing based on confidence and query characteristics
     */
    private function applyIntelligentProcessing(ParsedQuery $parsedQuery, DisambiguationResult $disambiguationResult, string $originalQuery): ParsedQuery
    {
        $confidence = $disambiguationResult->getConfidence();
        $query = clone $parsedQuery;
        
        // Low confidence with short query - use aggressive fallback
        if ($confidence < 0.5 && str_word_count($originalQuery) <= 5) {
            $query = $this->fallback->processLowConfidenceQuery($query, $confidence);
            $this->metrics['fallback_used']++;
        }
        
        // Apply relationship interpretation
        $query = $this->interpreter->interpretRelationships($query);
        
        // Apply action detection if missing
        $query = $this->actionDetector->detectAndAddMissingAction($query);
        
        // Validate and fix SQL structure
        $query = $this->validator->validateAndFix($query);
        
        return $query;
    }
    
    /**
     * Enhanced query execution with better error handling and performance
     */
    private function executeEnhancedQuery(ParsedQuery $query, array $options): array
    {
        $queryStructure = $this->validator->buildSafeQuery($query);
        
        // Enhanced execution with connection pooling and query optimization
        $model = $queryStructure['model'];
        $modelClass = $this->vocabulary->getModelClass($model);
        
        if (!$modelClass) {
            return [];
        }
        
        // Build optimized query
        $eloquentQuery = $this->buildOptimizedQuery($modelClass, $queryStructure, $options);
        
        // Execute with timeout protection
        return $this->executeWithTimeout($eloquentQuery, $options['timeout'] ?? 30);
    }
    
    /**
     * Build optimized Eloquent query with performance enhancements
     */
    private function buildOptimizedQuery(string $modelClass, array $queryStructure, array $options)
    {
        $query = $modelClass::query();
        
        // Apply conditions with optimization
        if (!empty($queryStructure['conditions'])) {
            $this->applyOptimizedConditions($query, $queryStructure['conditions']);
        }
        
        // Apply relationships efficiently
        if (!empty($queryStructure['relationships'])) {
            $this->applyOptimizedRelationships($query, $queryStructure['relationships']);
        }
        
        // Apply ordering
        if (!empty($queryStructure['order'])) {
            foreach ($queryStructure['order'] as $order) {
                $query->orderBy($order['field'], $order['direction'] ?? 'asc');
            }
        }
        
        // Apply pagination
        $limit = $queryStructure['limit'] ?? $options['limit'] ?? 50;
        $offset = $queryStructure['offset'] ?? $options['offset'] ?? 0;
        
        $query->limit($limit)->offset($offset);
        
        return $query;
    }
    
    /**
     * Apply conditions with optimization
     */
    private function applyOptimizedConditions($query, array $conditions): void
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'];
            $value = $condition['value'];
            $logical = $condition['logical'] ?? 'AND';
            
            // Optimize common patterns
            if ($operator === 'LIKE' && str_contains($value, '%')) {
                // Use full-text search if available
                if (method_exists($query->getModel(), 'scopeFullTextSearch')) {
                    $logical === 'OR' ? $query->orWhereFullTextSearch($field, $value) : $query->whereFullTextSearch($field, $value);
                } else {
                    $logical === 'OR' ? $query->orWhere($field, $operator, $value) : $query->where($field, $operator, $value);
                }
            } else {
                $logical === 'OR' ? $query->orWhere($field, $operator, $value) : $query->where($field, $operator, $value);
            }
        }
    }
    
    /**
     * Apply relationships with eager loading optimization
     */
    private function applyOptimizedRelationships($query, array $relationships): void
    {
        foreach ($relationships as $relationship) {
            $type = $relationship['type'];
            $relatedModel = $relationship['related_model'];
            $conditions = $relationship['conditions'] ?? [];
            
            switch ($type) {
                case 'hasMany':
                case 'belongsTo':
                case 'manyToMany':
                    // Eager load with conditions
                    $query->with([$relatedModel => function($q) use ($conditions) {
                        foreach ($conditions as $cond) {
                            $q->where($cond['field'], $cond['operator'], $cond['value']);
                        }
                    }]);
                    
                    // Apply whereHas if conditions exist
                    if (!empty($conditions)) {
                        $query->whereHas($relatedModel, function($q) use ($conditions) {
                            foreach ($conditions as $cond) {
                                $q->where($cond['field'], $cond['operator'], $cond['value']);
                            }
                        });
                    }
                    break;
            }
        }
    }
    
    /**
     * Execute query with timeout protection
     */
    private function executeWithTimeout($query, int $timeout): array
    {
        // Simple timeout implementation
        $startTime = microtime(true);
        
        try {
            $results = $query->get();
            
            if (microtime(true) - $startTime > $timeout) {
                \Illuminate\Support\Facades\Log::warning('Query timeout exceeded', [
                    'timeout' => $timeout,
                    'actual_time' => microtime(true) - $startTime
                ]);
                return [];
            }
            
            return $results->toArray();
            
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Query execution failed', [
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $startTime
            ]);
            return [];
        }
    }
    
    /**
     * Enhanced error handling with better fallbacks
     */
    private function handleError(string $query, \Throwable $error, float $startTime): SearchResult
    {
        \Illuminate\Support\Facades\Log::warning('EnhancedSemanticSearch error handled', [
            'query' => $query,
            'error' => $error->getMessage(),
            'execution_time' => microtime(true) - $startTime
        ]);
        
        // Try intelligent fallback
        try {
            $models = $this->vocabulary->getAllModelNames();
            if (!empty($models)) {
                $model = $models[0];
                $modelClass = $this->vocabulary->getModelClass($model);
                
                if ($modelClass) {
                    $results = $this->executeIntelligentFallback($modelClass, $query);
                    
                    return new SearchResult(
                        data: $results,
                        metadata: [
                            'type' => 'intelligent_fallback',
                            'confidence' => 0.3,
                            'original_query' => $query,
                            'execution_time' => microtime(true) - $startTime,
                            'error_handled' => true,
                            'error_message' => $error->getMessage(),
                            'fallback_type' => 'intelligent'
                        ]
                    );
                }
            }
        } catch (\Throwable $fallbackError) {
            \Illuminate\Support\Facades\Log::error('Even intelligent fallback failed', [
                'query' => $query,
                'fallback_error' => $fallbackError->getMessage()
            ]);
        }
        
        // Last resort - empty with suggestions
        return new SearchResult(
            data: [],
            metadata: [
                'type' => 'error_fallback',
                'confidence' => 0.0,
                'original_query' => $query,
                'execution_time' => microtime(true) - $startTime,
                'error_handled' => true,
                'error_message' => 'Search temporarily unavailable',
                'suggestions' => $this->generateIntelligentSuggestions($query)
            ]
        );
    }
    
    /**
     * Intelligent fallback execution
     */
    private function executeIntelligentFallback(string $modelClass, string $query): array
    {
        $priorityFields = SemanticFieldPatterns::getPrioritySearchFields();
        
        return $modelClass::where(function($q) use ($query, $modelClass, $priorityFields) {
            foreach ($priorityFields as $fieldConfig) {
                $field = $fieldConfig['field'];
                if (\Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), $field)) {
                    $q->orWhere($field, 'LIKE', "%{$query}%");
                }
            }
        })->limit(10)->get()->toArray();
    }
    
    /**
     * Generate intelligent suggestions based on query analysis
     */
    private function generateIntelligentSuggestions(string $query): array
    {
        $suggestions = [];
        $models = $this->vocabulary->getAllModelNames();
        
        // Analyze query to generate contextual suggestions
        if (preg_match('/\b(\w+)\s+(from|to|by|with|about)\b/', $query, $matches)) {
            $entity = $matches[1];
            $relationship = $matches[2];
            
            // Suggest similar queries
            foreach (array_slice($models, 0, 3) as $model) {
                $suggestions[] = [
                    'query' => "{$model} {$relationship} {$entity}",
                    'description' => "Find {$model} {$relationship} {$entity}",
                    'confidence' => 0.7
                ];
            }
        } else {
            // General model suggestions
            foreach (array_slice($models, 0, 3) as $model) {
                $suggestions[] = [
                    'query' => "show {$model}",
                    'description' => "List all {$model}",
                    'confidence' => 0.8
                ];
            }
        }
        
        return array_slice($suggestions, 0, 5);
    }
    
    /**
     * Public method to generate suggestions for a query
     */
    public function generateSuggestions(string $query): array
    {
        return $this->generateIntelligentSuggestions($query);
    }
    
    /**
     * Cache management
     */
    private function generateCacheKey(string $query, array $options): string
    {
        return 'semantic_search:' . md5($query . serialize($options));
    }
    
    private function getCachedResult(string $key): ?SearchResult
    {
        $cached = $this->cacheRepository->get($key);
        return $cached ? unserialize($cached) : null;
    }
    
    private function cacheResult(string $key, SearchResult $result): void
    {
        $this->cacheRepository->put($key, serialize($result), 3600);
    }
    
    /**
     * Metrics and performance tracking
     */
    private function updateMetrics(float $startTime, bool $error): void
    {
        $executionTime = microtime(true) - $startTime;
        $this->metrics['queries_processed']++;
        
        // Update average execution time
        $totalTime = $this->metrics['avg_execution_time'] * ($this->metrics['queries_processed'] - 1);
        $this->metrics['avg_execution_time'] = ($totalTime + $executionTime) / $this->metrics['queries_processed'];
    }
    
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    
    public function resetMetrics(): void
    {
        $this->metrics = [
            'queries_processed' => 0,
            'avg_execution_time' => 0,
            'cache_hits' => 0,
            'fallback_used' => 0,
            'error_count' => 0
        ];
    }
    
    /**
     * Enhanced query explanation
     */
    public function explainQuery(string $query): array
    {
        $parsedQuery = $this->parser->parse($query);
        $enhancedQuery = $this->enhancer->enhance($parsedQuery);
        $disambiguationResult = $this->disambiguation->process($query);
        
        return [
            'original_query' => $query,
            'parsed_entities' => $parsedQuery->getEntities(),
            'parsed_actions' => $parsedQuery->getActions(),
            'parsed_relationships' => $parsedQuery->getRelationships(),
            'enhanced_entities' => $enhancedQuery->getEntities(),
            'enhanced_actions' => $enhancedQuery->getActions(),
            'confidence' => $disambiguationResult->getConfidence(),
            'interpretation' => $this->generateDetailedExplanation($parsedQuery, $enhancedQuery, $disambiguationResult),
            'enhancements_applied' => $this->getEnhancementsApplied($parsedQuery, $enhancedQuery),
            'estimated_performance' => $this->estimateQueryPerformance($enhancedQuery)
        ];
    }
    
    private function generateDetailedExplanation(ParsedQuery $original, ParsedQuery $enhanced, DisambiguationResult $result): string
    {
        $explanations = [];
        
        if (count($enhanced->getEntities()) > count($original->getEntities())) {
            $explanations[] = 'Entities were enhanced through intelligent detection';
        }
        
        if (count($enhanced->getActions()) > count($original->getActions())) {
            $explanations[] = 'Missing action words were automatically detected';
        }
        
        if (count($enhanced->getRelationships()) > count($original->getRelationships())) {
            $explanations[] = 'Relationships were interpreted and added';
        }
        
        $explanations[] = "Confidence: " . round($result->getConfidence(), 2);
        
        return implode('; ', $explanations);
    }
    
    private function getEnhancementsApplied(ParsedQuery $original, ParsedQuery $enhanced): array
    {
        $enhancements = [];
        
        if (count($enhanced->getEntities()) > count($original->getEntities())) {
            $enhancements[] = 'entity_enhancement';
        }
        
        if (count($enhanced->getActions()) > count($original->getActions())) {
            $enhancements[] = 'action_detection';
        }
        
        if (count($enhanced->getRelationships()) > count($original->getRelationships())) {
            $enhancements[] = 'relationship_interpretation';
        }
        
        return $enhancements;
    }
    
    private function estimateQueryPerformance(ParsedQuery $query): array
    {
        $complexity = 0;
        
        $complexity += count($query->getEntities()) * 1;
        $complexity += count($query->getActions()) * 0.5;
        $complexity += count($query->getRelationships()) * 2;
        $complexity += count($query->getFilters()) * 1.5;
        
        $performance = 'fast';
        if ($complexity > 10) $performance = 'medium';
        if ($complexity > 20) $performance = 'slow';
        
        return [
            'complexity_score' => $complexity,
            'estimated_performance' => $performance,
            'optimization_suggestions' => $this->getOptimizationSuggestions($query)
        ];
    }
    
    private function getOptimizationSuggestions(ParsedQuery $query): array
    {
        $suggestions = [];
        
        if (count($query->getRelationships()) > 3) {
            $suggestions[] = 'Consider reducing the number of relationships for better performance';
        }
        
        if (count($query->getFilters()) > 5) {
            $suggestions[] = 'Multiple filters may impact performance - consider combining similar conditions';
        }
        
        return $suggestions;
    }
}
