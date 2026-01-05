<?php

namespace Venmail\SemanticSearch\Core;

use Venmail\SemanticSearch\Core\Validation\SQLStructureValidator;
use Venmail\SemanticSearch\Core\Fallback\AggressiveMappingFallback;
use Venmail\SemanticSearch\Core\Intelligence\RelationshipInterpreter;
use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\SearchResult;
use Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline;
use Venmail\SemanticSearch\Disambiguation\DisambiguationResult;
use Venmail\SemanticSearch\Parsing\MultilingualQueryParser;

class RobustSemanticSearchService
{
    private Vocabulary $vocabulary;
    private SQLStructureValidator $validator;
    private AggressiveMappingFallback $fallback;
    private RelationshipInterpreter $interpreter;
    private DisambiguationPipeline $disambiguation;
    private MultilingualQueryParser $parser;
    
    public function __construct(
        Vocabulary $vocabulary,
        SQLStructureValidator $validator,
        AggressiveMappingFallback $fallback,
        RelationshipInterpreter $interpreter,
        DisambiguationPipeline $disambiguation,
        MultilingualQueryParser $parser
    ) {
        $this->vocabulary = $vocabulary;
        $this->validator = $validator;
        $this->fallback = $fallback;
        $this->interpreter = $interpreter;
        $this->disambiguation = $disambiguation;
        $this->parser = $parser;
    }
    
    public function search(string $query, array $options = []): SearchResult
    {
        $startTime = microtime(true);
        
        try {
            // Step 1: Parse the query
            $parsedQuery = $this->parser->parse($query);
            
            // Step 2: Apply disambiguation (but never return clarification)
            $disambiguationResult = $this->disambiguation->process($query);
            
            // Step 3: Apply aggressive fallback for low confidence short queries
            if ($disambiguationResult->getConfidence() < 0.5 && str_word_count($query) <= 5) {
                $parsedQuery = $this->fallback->processLowConfidenceQuery($parsedQuery, $disambiguationResult->getConfidence());
            }
            
            // Step 4: Apply intelligent relationship interpretation
            $parsedQuery = $this->interpreter->interpretRelationships($parsedQuery);
            
            // Step 5: Validate and fix SQL structure
            $validatedQuery = $this->validator->validateAndFix($parsedQuery);
            
            // Step 6: Build and execute the query
            $queryStructure = $this->validator->buildSafeQuery($validatedQuery);
            $results = $this->executeQuery($queryStructure, $options);
            
            return new SearchResult(
                data: $results,
                metadata: [
                    'type' => 'search_results',
                    'confidence' => $disambiguationResult->getConfidence(),
                    'original_query' => $query,
                    'parsed_query' => $queryStructure,
                    'execution_time' => microtime(true) - $startTime,
                    'fallback_applied' => $disambiguationResult->getConfidence() < 0.5,
                    'relationships_interpreted' => count($parsedQuery->relationships) > 0
                ]
            );
            
        } catch (\Throwable $e) {
            // Never fail - always return something useful
            return $this->handleError($query, $e, $startTime);
        }
    }
    
    private function executeQuery(array $queryStructure, array $options): array
    {
        $model = $queryStructure['model'];
        $conditions = $queryStructure['conditions'] ?? [];
        $relationships = $queryStructure['relationships'] ?? [];
        $limit = $queryStructure['limit'] ?? $options['limit'] ?? 50;
        $offset = $queryStructure['offset'] ?? $options['offset'] ?? 0;
        
        // Get the Eloquent model class
        $modelClass = $this->vocabulary->getModelClass($model);
        if (!$modelClass) {
            return [];
        }
        
        // Start building the query
        $eloquentQuery = $modelClass::query();
        
        // Apply conditions
        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $operator = $condition['operator'];
            $value = $condition['value'];
            $logical = $condition['logical'] ?? 'AND';
            
            if ($logical === 'OR') {
                $eloquentQuery->orWhere($field, $operator, $value);
            } else {
                $eloquentQuery->where($field, $operator, $value);
            }
        }
        
        // Apply relationships
        foreach ($relationships as $relationship) {
            $type = $relationship['type'];
            $relatedModel = $relationship['related_model'];
            $relationshipConditions = $relationship['conditions'] ?? [];
            
            switch ($type) {
                case 'hasMany':
                case 'belongsTo':
                case 'manyToMany':
                    $eloquentQuery->with($relatedModel);
                    if (!empty($relationshipConditions)) {
                        $eloquentQuery->whereHas($relatedModel, function($q) use ($relationshipConditions) {
                            foreach ($relationshipConditions as $cond) {
                                $q->where($cond['field'], $cond['operator'], $cond['value']);
                            }
                        });
                    }
                    break;
            }
        }
        
        // Apply ordering
        if (isset($queryStructure['order'])) {
            foreach ($queryStructure['order'] as $order) {
                $eloquentQuery->orderBy($order['field'], $order['direction'] ?? 'asc');
            }
        }
        
        // Apply limit and offset
        $eloquentQuery->limit($limit)->offset($offset);
        
        // Execute and return results
        return $eloquentQuery->get()->toArray();
    }
    
    private function handleError(string $query, \Throwable $error, float $startTime): SearchResult
    {
        \Illuminate\Support\Facades\Log::warning('RobustSemanticSearch error handled', [
            'query' => $query,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]);
        
        // Try to provide a basic search result
        try {
            // Get the first available model
            $models = $this->vocabulary->getAllModelNames();
            if (!empty($models)) {
                $model = $models[0];
                $modelClass = $this->vocabulary->getModelClass($model);
                
                if ($modelClass) {
                    // Simple search across common fields
                    $results = $modelClass::where(function($q) use ($query, $modelClass) {
                        $commonFields = ['subject', 'content', 'body', 'description', 'title', 'name'];
                        foreach ($commonFields as $field) {
                            if (\Illuminate\Support\Facades\Schema::hasColumn((new $modelClass)->getTable(), $field)) {
                                $q->orWhere($field, 'LIKE', "%{$query}%");
                            }
                        }
                    })->limit(10)->get()->toArray();
                    
                    return new SearchResult(
                        data: $results,
                        metadata: [
                            'type' => 'fallback_results',
                            'confidence' => 0.3,
                            'original_query' => $query,
                            'execution_time' => microtime(true) - $startTime,
                            'error_handled' => true,
                            'error_message' => $error->getMessage()
                        ]
                    );
                }
            }
        } catch (\Throwable $fallbackError) {
            \Illuminate\Support\Facades\Log::error('Even fallback search failed', [
                'query' => $query,
                'fallback_error' => $fallbackError->getMessage()
            ]);
        }
        
        // Last resort - empty result with explanation
        return new SearchResult(
            data: [],
            metadata: [
                'type' => 'error_fallback',
                'confidence' => 0.0,
                'original_query' => $query,
                'execution_time' => microtime(true) - $startTime,
                'error_handled' => true,
                'error_message' => 'Search temporarily unavailable',
                'suggestions' => $this->generateBasicSuggestions($query)
            ]
        );
    }
    
    public function generateSuggestions(string $query): array
    {
        $suggestions = [];
        
        // Get fallback suggestions
        $fallbackSuggestions = $this->fallback->generateFallbackSuggestions($query);
        $suggestions = array_merge($suggestions, $fallbackSuggestions);
        
        // Get relationship suggestions
        $relationshipSuggestions = $this->interpreter->generateRelationshipSuggestions($query);
        $suggestions = array_merge($suggestions, $relationshipSuggestions);
        
        // Get model-based suggestions
        $models = $this->vocabulary->getAllModelNames();
        foreach (array_slice($models, 0, 3) as $model) {
            $suggestions[] = [
                'query' => "show {$model}",
                'description' => "List all {$model}",
                'confidence' => 0.8
            ];
        }
        
        // Sort by confidence and limit
        usort($suggestions, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        return array_slice($suggestions, 0, 5);
    }
    
    private function generateBasicSuggestions(string $query): array
    {
        $suggestions = [];
        $models = $this->vocabulary->getAllModelNames();
        
        if (!empty($models)) {
            $suggestions[] = [
                'query' => "show {$models[0]}",
                'description' => "List all {$models[0]}"
            ];
            
            if (count($models) > 1) {
                $suggestions[] = [
                    'query' => "show {$models[1]}",
                    'description' => "List all {$models[1]}"
                ];
            }
        }
        
        $suggestions[] = [
            'query' => "help",
            'description' => "Show search help"
        ];
        
        return $suggestions;
    }
    
    public function validateQueryStructure(array $queryStructure): array
    {
        $errors = [];
        $warnings = [];
        
        // Validate model
        if (empty($queryStructure['model'])) {
            $errors[] = 'No model specified';
        } elseif (!$this->vocabulary->isModel($queryStructure['model'])) {
            $errors[] = "Unknown model: {$queryStructure['model']}";
        }
        
        // Validate fields
        if (isset($queryStructure['fields'])) {
            $model = $queryStructure['model'];
            $validFields = $this->vocabulary->getFieldsForModel($model);
            
            foreach ($queryStructure['fields'] as $field) {
                if (!in_array($field, $validFields)) {
                    $warnings[] = "Unknown field: {$field}";
                }
            }
        }
        
        // Validate conditions
        if (isset($queryStructure['conditions'])) {
            foreach ($queryStructure['conditions'] as $condition) {
                if (!in_array(strtolower($condition['operator']), ['=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'not in'])) {
                    $errors[] = "Invalid operator: {$condition['operator']}";
                }
                
                if (empty($condition['field'])) {
                    $errors[] = 'Condition without field';
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    public function getQueryExplanation(string $query): array
    {
        $parsedQuery = $this->parser->parse($query);
        $disambiguationResult = $this->disambiguation->process($query);
        
        return [
            'original_query' => $query,
            'parsed_entities' => $parsedQuery->getEntities(),
            'parsed_actions' => $parsedQuery->getActions(),
            'parsed_relationships' => $parsedQuery->getRelationships(),
            'confidence' => $disambiguationResult->getConfidence(),
            'interpretation' => $this->explainInterpretation($parsedQuery, $disambiguationResult)
        ];
    }
    
    private function explainInterpretation(ParsedQuery $parsedQuery, DisambiguationResult $disambiguationResult): string
    {
        $explanation = [];
        
        if (!empty($parsedQuery->getEntities())) {
            $entityNames = array_map(fn($e) => $e->value, $parsedQuery->getEntities());
            $explanation[] = "Found entities: " . implode(', ', $entityNames);
        }
        
        if (!empty($parsedQuery->getActions())) {
            $actionNames = array_map(fn($a) => $a->value, $parsedQuery->getActions());
            $explanation[] = "Actions: " . implode(', ', $actionNames);
        }
        
        if (!empty($parsedQuery->getRelationships())) {
            $relationships = array_map(fn($r) => $r->type, $parsedQuery->getRelationships());
            $explanation[] = "Relationships: " . implode(', ', $relationships);
        }
        
        $explanation[] = "Confidence: " . round($disambiguationResult->getConfidence(), 2);
        
        return implode('; ', $explanation);
    }
}
