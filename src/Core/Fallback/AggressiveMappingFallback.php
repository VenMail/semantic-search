<?php

namespace Venmail\SemanticSearch\Core\Fallback;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Entity;

class AggressiveMappingFallback
{
    private Vocabulary $vocabulary;
    private array $relationshipPatterns = [
        'from' => ['sender', 'from_name', 'from_email', 'user_id'],
        'to' => ['recipient', 'to_name', 'to_email', 'recipient_id'],
        'by' => ['creator', 'author', 'user_id', 'created_by'],
        'with' => ['associated', 'related', 'linked'],
        'about' => ['subject', 'content', 'description', 'title'],
        'in' => ['category', 'folder', 'status', 'location'],
        'for' => ['recipient', 'target', 'purpose'],
        'on' => ['date', 'created_at', 'updated_at', 'timestamp'],
        'at' => ['time', 'created_at', 'updated_at', 'timestamp']
    ];
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }
    
    public function processLowConfidenceQuery(ParsedQuery $query, float $confidence): ParsedQuery
    {
        // Only apply fallback for short queries with low confidence
        if ($confidence > 0.5 || str_word_count($query->getOriginal()) > 5) {
            return $query;
        }
        
        $original = strtolower($query->getOriginal());
        
        // Pattern 1: "mails from fred" -> interpret as mails with from field containing fred
        if (preg_match('/(\w+)\s+(from|to|by|with|about|in|for|on|at)\s+(\w+)/', $original, $matches)) {
            return $this->handleRelationshipPattern($query, $matches[1], $matches[2], $matches[3]);
        }
        
        // Pattern 2: "fred mails" -> interpret as mails containing fred
        if (preg_match('/(\w+)\s+(\w+)/', $original, $matches)) {
            return $this->handleEntityPattern($query, $matches[1], $matches[2]);
        }
        
        // Pattern 3: Single word -> find closest entity and search
        if (preg_match('/^(\w+)$/', $original, $matches)) {
            return $this->handleSingleWordPattern($query, $matches[1]);
        }
        
        return $query;
    }
    
    private function handleRelationshipPattern(ParsedQuery $query, string $entity, string $relationship, string $target): ParsedQuery
    {
        $newQuery = clone $query;
        
        // Find the closest model
        $model = $this->findClosestModel($entity);
        if (!$model) {
            $model = $this->findClosestModel($target);
        }
        
        if ($model) {
            // Set the primary model
            $this->setPrimaryModel($newQuery, $model);
            
            // Add relationship condition
            $fields = $this->getRelationshipFields($relationship, $model);
            if (!empty($fields)) {
                $this->addSearchCondition($newQuery, $fields[0], 'LIKE', "%{$target}%");
            } else {
                // Fallback: search in common fields
                $commonFields = $this->getCommonFields($model);
                foreach ($commonFields as $field) {
                    $this->addSearchCondition($newQuery, $field, 'LIKE', "%{$target}%");
                }
            }
        }
        
        return $newQuery;
    }
    
    private function handleEntityPattern(ParsedQuery $query, string $first, string $second): ParsedQuery
    {
        $newQuery = clone $query;
        
        // Try to identify which is the model
        $firstModel = $this->findClosestModel($first);
        $secondModel = $this->findClosestModel($second);
        
        if ($firstModel && !$secondModel) {
            // First is model, second is search term
            $this->setPrimaryModel($newQuery, $firstModel);
            $this->addGeneralSearchCondition($newQuery, $second);
        } elseif (!$firstModel && $secondModel) {
            // Second is model, first is search term
            $this->setPrimaryModel($newQuery, $secondModel);
            $this->addGeneralSearchCondition($newQuery, $first);
        } elseif ($firstModel && $secondModel) {
            // Both are models - choose the first one and search for the second
            $this->setPrimaryModel($newQuery, $firstModel);
            $this->addGeneralSearchCondition($newQuery, $second);
        } else {
            // Neither is clearly a model - use first as model if available, otherwise search both
            $models = $this->vocabulary->getAllModelNames();
            if (!empty($models)) {
                $this->setPrimaryModel($newQuery, $models[0]);
                $this->addGeneralSearchCondition($newQuery, $first);
                $this->addGeneralSearchCondition($newQuery, $second);
            }
        }
        
        return $newQuery;
    }
    
    private function handleSingleWordPattern(ParsedQuery $query, string $word): ParsedQuery
    {
        $newQuery = clone $query;
        
        // Check if it's a model
        $model = $this->findClosestModel($word);
        if ($model) {
            $this->setPrimaryModel($newQuery, $model);
            // Just list all records for this model
            return $newQuery;
        }
        
        // Otherwise, search across all models
        $models = $this->vocabulary->getAllModelNames();
        if (!empty($models)) {
            $this->setPrimaryModel($newQuery, $models[0]);
            $this->addGeneralSearchCondition($newQuery, $word);
        }
        
        return $newQuery;
    }
    
    private function findClosestModel(string $input): ?string
    {
        $models = $this->vocabulary->getAllModelNames();
        $input = strtolower($input);
        
        // Exact match
        if (in_array($input, $models)) {
            return $input;
        }
        
        // Check singular/plural variations
        foreach ($models as $model) {
            if (strtolower($input) === strtolower($model) ||
                strtolower($input) === strtolower(\Illuminate\Support\Str::singular($model)) ||
                strtolower($input) === strtolower(\Illuminate\Support\Str::plural($model))) {
                return $model;
            }
        }
        
        // Find closest match using Levenshtein distance
        $closest = null;
        $minDistance = PHP_INT_MAX;
        
        foreach ($models as $model) {
            $distance = levenshtein($input, strtolower($model));
            if ($distance < $minDistance && $distance <= 2) {
                $minDistance = $distance;
                $closest = $model;
            }
        }
        
        return $closest;
    }
    
    private function getRelationshipFields(string $relationship, string $model): array
    {
        $patterns = $this->relationshipPatterns[$relationship] ?? [];
        $modelFields = $this->vocabulary->getFieldsForModel($model);
        
        // Find fields that match the pattern
        $matchingFields = [];
        foreach ($patterns as $pattern) {
            foreach ($modelFields as $field) {
                if (str_contains(strtolower($field), $pattern)) {
                    $matchingFields[] = $field;
                }
            }
        }
        
        return $matchingFields;
    }
    
    private function getCommonFields(string $model): array
    {
        $fields = $this->vocabulary->getFieldsForModel($model);
        
        // Prioritize common searchable fields
        $priorityFields = ['subject', 'content', 'body', 'description', 'title', 'name', 'email'];
        
        foreach ($priorityFields as $priority) {
            foreach ($fields as $field) {
                if (str_contains(strtolower($field), $priority)) {
                    return [$field];
                }
            }
        }
        
        // Return first few fields as fallback
        return array_slice($fields, 0, 3);
    }
    
    private function setPrimaryModel(ParsedQuery $query, string $model): void
    {
        // Remove existing entities and add the primary model
        $query->entities = array_filter($query->entities, fn($e) => $e->type !== 'model');
        $query->entities[] = new Entity('model', $model, 1.0);
    }
    
    private function addSearchCondition(ParsedQuery $query, string $field, string $operator, string $value): void
    {
        $query->filters[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'type' => 'condition'
        ];
    }
    
    private function addGeneralSearchCondition(ParsedQuery $query, string $searchTerm): void
    {
        $model = $this->getPrimaryModel($query);
        if (!$model) return;
        
        $fields = $this->getCommonFields($model);
        foreach ($fields as $field) {
            $this->addSearchCondition($query, $field, 'LIKE', "%{$searchTerm}%");
        }
    }
    
    private function getPrimaryModel(ParsedQuery $query): ?string
    {
        foreach ($query->entities as $entity) {
            if ($entity->type === 'model') {
                return $entity->value;
            }
        }
        return null;
    }
    
    public function generateFallbackSuggestions(string $query): array
    {
        $suggestions = [];
        $models = $this->vocabulary->getAllModelNames();
        
        // Suggest model-based queries
        foreach (array_slice($models, 0, 3) as $model) {
            $suggestions[] = [
                'query' => "{$query} in {$model}",
                'description' => "Search for '{$query}' in {$model}",
                'confidence' => 0.7
            ];
        }
        
        // Suggest relationship-based queries
        if (!empty($models)) {
            foreach (array_keys($this->relationshipPatterns) as $relationship) {
                $suggestions[] = [
                    'query' => "{$models[0]} {$relationship} {$query}",
                    'description' => "Find {$models[0]} {$relationship} {$query}",
                    'confidence' => 0.6
                ];
            }
        }
        
        return $suggestions;
    }
}
