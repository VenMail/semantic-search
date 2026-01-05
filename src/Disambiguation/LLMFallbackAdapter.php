<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Entity;
use Venmail\SemanticSearch\Data\Filter;
use Venmail\SemanticSearch\Data\Relationship;
use Venmail\SemanticSearch\Data\Action;
use Venmail\SemanticSearch\Data\ProjectMetadata;

class LLMFallbackAdapter
{
    private ProjectMetadata $metadata;
    private string $provider;
    private string $model;
    private string $apiKey;
    private int $timeout;
    private float $confidenceThreshold;
    private int $cacheTtl;
    
    public function __construct(ProjectMetadata $metadata)
    {
        $this->metadata = $metadata;
        $this->provider = config('semantic-search.llm.provider', 'openai');
        $this->model = config('semantic-search.llm.model', 'gpt-3.5-turbo');
        $this->apiKey = config('semantic-search.llm.api_key', '');
        $this->timeout = config('semantic-search.llm.timeout', 10);
        $this->confidenceThreshold = config('semantic-search.llm.confidence_threshold', 0.7);
        $this->cacheTtl = config('semantic-search.llm.cache_ttl', config('semantic-search.cache.default_ttl', 3600));
    }
    
    public function process(string $query): DisambiguationResult
    {
        if (!config('semantic-search.llm.enabled', false)) {
            return new DisambiguationResult(
                originalQuery: $query,
                correctedQuery: $query,
                confidence: 0.0,
                parseable: false,
                failureReason: 'LLM fallback disabled',
                suggestions: [],
                source: 'llm_fallback',
                processingTime: 0.0
            );
        }
        
        if (empty($this->apiKey)) {
            Log::warning('LLM fallback attempted but API key not configured');
            return new DisambiguationResult(
                originalQuery: $query,
                correctedQuery: $query,
                confidence: 0.0,
                parseable: false,
                failureReason: 'LLM API key not configured',
                suggestions: [],
                source: 'llm_fallback',
                processingTime: 0.0
            );
        }
        
        $startTime = microtime(true);
        
        // Check cache first
        $cacheKey = 'semantic_search:llm:' . md5($query);
        $cached = Cache::get($cacheKey);
        if ($cached instanceof DisambiguationResult) {
            return $cached;
        }
        
        try {
            $prompt = $this->buildPrompt($query);
            $response = $this->callLLM($prompt);
            $parsed = $this->parseLLMResponse($response, $query);
            
            $processingTime = microtime(true) - $startTime;
            
            $result = new DisambiguationResult(
                originalQuery: $query,
                correctedQuery: $parsed['corrected_query'] ?? $query,
                confidence: $parsed['confidence'] ?? 0.5,
                parseable: ($parsed['confidence'] ?? 0.0) >= $this->confidenceThreshold,
                failureReason: ($parsed['confidence'] ?? 0.0) < $this->confidenceThreshold 
                    ? 'LLM confidence below threshold' 
                    : null,
                suggestions: $parsed['suggestions'] ?? [],
                source: 'llm_fallback',
                processingTime: $processingTime,
                llmParsedData: $parsed
            );
            
            // Cache successful results
            if ($result->isParseable()) {
                Cache::put($cacheKey, $result, $this->cacheTtl);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::warning('LLM fallback failed', [
                'query' => $query,
                'error' => $e->getMessage(),
                'provider' => $this->provider
            ]);
            
            return new DisambiguationResult(
                originalQuery: $query,
                correctedQuery: $query,
                confidence: 0.0,
                parseable: false,
                failureReason: 'LLM fallback failed: ' . $e->getMessage(),
                suggestions: [],
                source: 'llm_fallback',
                processingTime: microtime(true) - $startTime
            );
        }
    }
    
    public function parseToParsedQuery(string $query, array $llmData): ParsedQuery
    {
        $entities = [];
        $filters = [];
        $relationships = [];
        $actions = [];
        $aggregations = [];
        $tokens = preg_split('/\s+/', strtolower($query));
        
        // Parse entities from LLM response
        foreach ($llmData['entities'] ?? [] as $entityData) {
            $entities[] = new Entity(
                type: $entityData['type'] ?? 'model',
                value: $entityData['name'] ?? '',
                mapping: $entityData['mapping'] ?? null,
                confidence: $entityData['confidence'] ?? 0.7,
                locale: 'en'
            );
        }
        
        // Parse filters
        foreach ($llmData['filters'] ?? [] as $filterData) {
            $filters[] = new Filter(
                field: $filterData['field'] ?? '',
                operator: $filterData['operator'] ?? '=',
                value: $filterData['value'] ?? null,
                mapping: $filterData['mapping'] ?? null,
                confidence: $filterData['confidence'] ?? 0.7,
                locale: 'en'
            );
        }
        
        // Parse relationships
        foreach ($llmData['relationships'] ?? [] as $relData) {
            $relationships[] = new Relationship(
                from: $relData['from'] ?? '',
                to: $relData['to'] ?? '',
                mapping: $relData['mapping'] ?? null,
                type: $relData['type'] ?? null,
                confidence: $relData['confidence'] ?? 0.7,
                locale: 'en'
            );
        }
        
        // Parse aggregations
        foreach ($llmData['aggregations'] ?? [] as $aggData) {
            $aggregations[] = new \Venmail\SemanticSearch\Data\Aggregation(
                type: $aggData['type'] ?? 'count',
                field: $aggData['field'] ?? null,
                alias: $aggData['alias'] ?? null,
                groupBy: $aggData['group_by'] ?? null
            );
        }
        
        // Parse actions
        if (isset($llmData['intent'])) {
            $actions[] = new Action(
                type: 'action',
                value: $llmData['intent'],
                mapping: $llmData['intent'],
                confidence: 0.8,
                locale: 'en'
            );
        }
        
        // Extract boolean operator
        $booleanOperator = $llmData['boolean_logic']['operator'] ?? 'AND';
        
        return new ParsedQuery(
            original: $query,
            tokens: $tokens,
            entities: $entities,
            actions: $actions,
            filters: $filters,
            relationships: $relationships,
            aggregations: $aggregations,
            booleanOperator: $booleanOperator,
            locale: 'en'
        );
    }
    
    private function buildPrompt(string $query): string
    {
        $context = $this->buildProjectContext();
        
        return <<<PROMPT
You are a Laravel query parser assistant. Given a natural language query and project context, 
extract the semantic components needed to build an Eloquent query.

Project Context:
{$context}

User Query: "{$query}"

Please analyze the query and return a JSON response with:
{
  "entities": [
    {
      "type": "model|field|action",
      "name": "entity_name",
      "confidence": 0.8,
      "mapping": "actual_laravel_class_or_field"
    }
  ],
  "relationships": [
    {
      "from": "source_model",
      "to": "target_model", 
      "type": "hasMany|belongsTo|hasOne|belongsToMany",
      "confidence": 0.7,
      "mapping": "relationship_method_name"
    }
  ],
  "intent": "list|filter|aggregate|relationship",
  "filters": [
    {
      "field": "field_name",
      "operator": ">|<|=|>=|<=|!=",
      "value": "filter_value",
      "confidence": 0.9,
      "mapping": "model.field"
    }
  ],
  "aggregations": [
    {
      "type": "count|sum|avg|min|max",
      "field": "field_name",
      "alias": "result_alias"
    }
  ],
  "boolean_logic": {
    "operator": "AND|OR",
    "conditions": []
  },
  "confidence": 0.75,
  "corrected_query": "corrected version if needed",
  "explanation": "Brief explanation of parsing logic",
  "suggestions": ["suggestion1", "suggestion2"]
}

Focus on accuracy. If you're uncertain about any component, set confidence below 0.6.
Only return valid JSON, no markdown formatting.
PROMPT;
    }
    
    private function buildProjectContext(): string
    {
        $context = "Available Models:\n";
        
        $models = $this->metadata->getModels();
        foreach ($models as $model => $data) {
            $context .= "- {$model}:\n";
            
            // Add attributes
            $attributes = $data['attributes'] ?? [];
            if (!empty($attributes)) {
                $context .= "  Attributes: " . implode(', ', array_keys($attributes)) . "\n";
            }
            
            // Add relationships
            $relationships = $data['relationships'] ?? [];
            if (!empty($relationships)) {
                $relList = [];
                foreach ($relationships as $relName => $relData) {
                    $type = $relData['type'] ?? 'unknown';
                    $relList[] = sprintf('%s (%s)', $relName, $type);
                }
                $context .= '  Relationships: ' . implode(', ', $relList) . "\n";
            }
            
            // Add scopes
            $scopes = $data['scopes'] ?? [];
            if (!empty($scopes)) {
                $context .= "  Scopes: " . implode(', ', array_keys($scopes)) . "\n";
            }
        }
        
        $context .= "\nAvailable Controllers:\n";
        $controllers = $this->metadata->getControllers();
        foreach ($controllers as $controller => $data) {
            $methods = $data['methods'] ?? [];
            if (!empty($methods)) {
                $methodNames = array_column($methods, 'name');
                $context .= "- {$controller}: " . implode(', ', $methodNames) . "\n";
            }
        }
        
        return $context;
    }
    
    private function callLLM(string $prompt): string
    {
        if ($this->provider === 'openai') {
            return $this->callOpenAI($prompt);
        }
        
        throw new \InvalidArgumentException("Unsupported LLM provider: {$this->provider}");
    }
    
    private function callOpenAI(string $prompt): string
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a Laravel query parser. Always return valid JSON only, no markdown.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ]);
        
        if (!$response->successful()) {
            throw new \RuntimeException(
                "OpenAI API error: " . $response->body() . 
                " (Status: " . $response->status() . ")"
            );
        }
        
        $data = $response->json();
        return $data['choices'][0]['message']['content'] ?? '';
    }
    
    private function parseLLMResponse(string $response, string $originalQuery): array
    {
        try {
            // Clean response - remove markdown code blocks if present
            $cleaned = preg_replace('/```json\s*/', '', $response);
            $cleaned = preg_replace('/```\s*/', '', $cleaned);
            $cleaned = trim($cleaned);
            
            $parsed = json_decode($cleaned, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(
                    "Invalid JSON response: " . json_last_error_msg() . 
                    "\nResponse: " . substr($cleaned, 0, 200)
                );
            }
            
            // Validate response structure
            $required = ['entities', 'confidence'];
            foreach ($required as $key) {
                if (!isset($parsed[$key])) {
                    throw new \InvalidArgumentException("Missing required key: {$key}");
                }
            }
            
            // Validate confidence threshold
            if ($parsed['confidence'] < 0.5) {
                Log::info('LLM returned low confidence', [
                    'confidence' => $parsed['confidence'],
                    'query' => $originalQuery
                ]);
            }
            
            // Ensure arrays exist
            $parsed['entities'] = $parsed['entities'] ?? [];
            $parsed['filters'] = $parsed['filters'] ?? [];
            $parsed['relationships'] = $parsed['relationships'] ?? [];
            $parsed['aggregations'] = $parsed['aggregations'] ?? [];
            $parsed['suggestions'] = $parsed['suggestions'] ?? [];
            
            return $parsed;
            
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid LLM response: " . $e->getMessage() . 
                "\nResponse: " . substr($response, 0, 500)
            );
        }
    }
}

