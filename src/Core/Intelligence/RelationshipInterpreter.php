<?php

namespace Venmail\SemanticSearch\Core\Intelligence;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;

class RelationshipInterpreter
{
    private Vocabulary $vocabulary;
    private array $relationshipMappings;
    private array $fieldMappings;
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
        $this->initializeMappings();
    }
    
    private function initializeMappings(): void
    {
        $this->relationshipMappings = [
            // Direct relationships
            'from' => [
                'semantic' => 'sender',
                'field_patterns' => ['from', 'sender', 'author', 'creator', 'user_id'],
                'inverse' => 'to'
            ],
            'to' => [
                'semantic' => 'recipient',
                'field_patterns' => ['to', 'recipient', 'receiver', 'target'],
                'inverse' => 'from'
            ],
            'by' => [
                'semantic' => 'creator',
                'field_patterns' => ['by', 'creator', 'author', 'user_id', 'created_by'],
                'inverse' => null
            ],
            'with' => [
                'semantic' => 'associated',
                'field_patterns' => ['with', 'associated', 'related', 'linked', 'connected'],
                'inverse' => 'with'
            ],
            'about' => [
                'semantic' => 'subject',
                'field_patterns' => ['about', 'subject', 'content', 'description', 'title', 'topic'],
                'inverse' => null
            ],
            'in' => [
                'semantic' => 'container',
                'field_patterns' => ['in', 'category', 'folder', 'status', 'location', 'group'],
                'inverse' => null
            ],
            'for' => [
                'semantic' => 'purpose',
                'field_patterns' => ['for', 'purpose', 'target', 'intended', 'recipient'],
                'inverse' => null
            ],
            'on' => [
                'semantic' => 'date',
                'field_patterns' => ['on', 'date', 'created_at', 'updated_at', 'timestamp', 'time'],
                'inverse' => null
            ],
            'at' => [
                'semantic' => 'time',
                'field_patterns' => ['at', 'time', 'created_at', 'updated_at', 'timestamp', 'hour'],
                'inverse' => null
            ],
            'during' => [
                'semantic' => 'period',
                'field_patterns' => ['during', 'period', 'range', 'between', 'within'],
                'inverse' => null
            ],
            'before' => [
                'semantic' => 'prior',
                'field_patterns' => ['before', 'prior', 'previous', 'earlier', '<'],
                'inverse' => 'after'
            ],
            'after' => [
                'semantic' => 'subsequent',
                'field_patterns' => ['after', 'subsequent', 'later', '>', 'following'],
                'inverse' => 'before'
            ],
            'since' => [
                'semantic' => 'from_date',
                'field_patterns' => ['since', 'from_date', 'start_date', '>='],
                'inverse' => 'until'
            ],
            'until' => [
                'semantic' => 'to_date',
                'field_patterns' => ['until', 'to_date', 'end_date', '<='],
                'inverse' => 'since'
            ]
        ];
        
        $this->fieldMappings = [
            'email' => ['email', 'email_address', 'mail', 'address'],
            'name' => ['name', 'full_name', 'display_name', 'title'],
            'date' => ['date', 'created_at', 'updated_at', 'timestamp', 'time'],
            'id' => ['id', 'user_id', 'identifier', 'key'],
            'content' => ['content', 'body', 'message', 'text', 'description'],
            'subject' => ['subject', 'title', 'heading', 'topic']
        ];
    }
    
    public function interpretRelationships(ParsedQuery $query): ParsedQuery
    {
        $newQuery = clone $query;
        $tokens = $query->getTokens();
        
        // Find relationship patterns in tokens
        $relationships = $this->extractRelationships($tokens);
        
        // Apply intelligent interpretation
        foreach ($relationships as $relationship) {
            $this->applyRelationshipInterpretation($newQuery, $relationship);
        }
        
        return $newQuery;
    }
    
    private function extractRelationships(array $tokens): array
    {
        $relationships = [];
        $n = count($tokens);
        
        for ($i = 0; $i < $n; $i++) {
            $token = strtolower($tokens[$i]);
            
            if (isset($this->relationshipMappings[$token])) {
                // Check for context before and after
                $contextBefore = $i > 0 ? $tokens[$i - 1] : null;
                $contextAfter = $i < $n - 1 ? $tokens[$i + 1] : null;
                
                $relationships[] = [
                    'relationship' => $token,
                    'context_before' => $contextBefore,
                    'context_after' => $contextAfter,
                    'position' => $i
                ];
            }
        }
        
        return $relationships;
    }
    
    private function applyRelationshipInterpretation(ParsedQuery $query, array $relationship): void
    {
        $rel = $relationship['relationship'];
        $mapping = $this->relationshipMappings[$rel];
        
        // Determine the target (what we're relating to)
        $target = $this->determineTarget($query, $relationship);
        
        if (!$target) return;
        
        // Find the appropriate field for this relationship
        $field = $this->findRelationshipField($query, $rel, $target);
        
        if ($field) {
            // Create appropriate filter condition
            $this->createRelationshipFilter($query, $field, $target, $mapping);
        }
    }
    
    private function determineTarget(ParsedQuery $query, array $relationship): ?string
    {
        $contextAfter = $relationship['context_after'];
        $contextBefore = $relationship['context_before'];
        
        // Priority 1: Direct context after the relationship
        if ($contextAfter && !$this->isStopWord($contextAfter)) {
            return $contextAfter;
        }
        
        // Priority 2: Direct context before the relationship
        if ($contextBefore && !$this->isStopWord($contextBefore)) {
            return $contextBefore;
        }
        
        // Priority 3: Look for entities in the query
        foreach ($query->getEntities() as $entity) {
            if ($entity->type === 'person' || $entity->type === 'user') {
                return $entity->value;
            }
        }
        
        // Priority 4: Look for values in filters
        foreach ($query->filters as $filter) {
            if (isset($filter['value']) && is_string($filter['value'])) {
                return $filter['value'];
            }
        }
        
        return null;
    }
    
    private function findRelationshipField(ParsedQuery $query, string $relationship, string $target): ?string
    {
        $mapping = $this->relationshipMappings[$relationship];
        $model = $this->getPrimaryModel($query);
        
        if (!$model) return null;
        
        $modelFields = $this->vocabulary->getFieldsForModel($model);
        
        // Try to find exact field match
        foreach ($mapping['field_patterns'] as $pattern) {
            foreach ($modelFields as $field) {
                if (strtolower($field) === $pattern) {
                    return $field;
                }
            }
        }
        
        // Try to find partial match
        foreach ($mapping['field_patterns'] as $pattern) {
            foreach ($modelFields as $field) {
                if (str_contains(strtolower($field), $pattern)) {
                    return $field;
                }
            }
        }
        
        // Try to match based on target type
        $targetType = $this->inferTargetType($target);
        if ($targetType && isset($this->fieldMappings[$targetType])) {
            foreach ($this->fieldMappings[$targetType] as $fieldPattern) {
                foreach ($modelFields as $field) {
                    if (str_contains(strtolower($field), $fieldPattern)) {
                        return $field;
                    }
                }
            }
        }
        
        return null;
    }
    
    private function inferTargetType(string $target): ?string
    {
        // Email detection
        if (filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        
        // ID detection (numeric or pattern like 1-251112-1744-296534-992)
        if (is_numeric($target) || preg_match('/^\d+-\d+-\d+-\d+$/', $target)) {
            return 'id';
        }
        
        // Date detection
        if (preg_match('/\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4}|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec/i', $target)) {
            return 'date';
        }
        
        // Name detection (simple heuristic)
        if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?$/', $target)) {
            return 'name';
        }
        
        return null;
    }
    
    private function createRelationshipFilter(ParsedQuery $query, string $field, string $target, array $mapping): void
    {
        $operator = $this->determineOperator($mapping, $target);
        $value = $this->formatValue($target, $operator, $mapping);
        
        // Add or update the filter
        $existingFilterIndex = $this->findExistingFilter($query, $field);
        
        if ($existingFilterIndex !== null) {
            // Update existing filter
            $query->filters[$existingFilterIndex] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'type' => 'relationship',
                'relationship' => $mapping['semantic']
            ];
        } else {
            // Add new filter
            $query->filters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
                'type' => 'relationship',
                'relationship' => $mapping['semantic']
            ];
        }
    }
    
    private function determineOperator(array $mapping, string $target): string
    {
        $semantic = $mapping['semantic'];
        
        switch ($semantic) {
            case 'date':
            case 'time':
                return '=';
            
            case 'prior':
            case 'subsequent':
                return str_contains($target, '-') ? '<=' : '<';
            
            case 'from_date':
            case 'to_date':
                return '>=';
            
            case 'period':
                return 'BETWEEN';
            
            default:
                return 'LIKE';
        }
    }
    
    private function formatValue(string $value, string $operator, array $mapping): string
    {
        if ($operator === 'LIKE') {
            return "%{$value}%";
        }
        
        if ($operator === 'BETWEEN') {
            // For period relationships, we'd need to parse date ranges
            return $value;
        }
        
        return $value;
    }
    
    private function findExistingFilter(ParsedQuery $query, string $field): ?int
    {
        foreach ($query->filters as $index => $filter) {
            if (isset($filter['field']) && $filter['field'] === $field) {
                return $index;
            }
        }
        return null;
    }
    
    private function getPrimaryModel(ParsedQuery $query): ?string
    {
        foreach ($query->getEntities() as $entity) {
            if ($entity->type === 'model') {
                return $entity->value;
            }
        }
        return null;
    }
    
    private function isStopWord(string $word): bool
    {
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];
        return in_array(strtolower($word), $stopWords);
    }
    
    public function generateRelationshipSuggestions(string $query): array
    {
        $suggestions = [];
        $models = $this->vocabulary->getAllModelNames();
        
        foreach (array_keys($this->relationshipMappings) as $relationship) {
            if (str_contains(strtolower($query), $relationship)) {
                continue; // Already has this relationship
            }
            
            foreach (array_slice($models, 0, 2) as $model) {
                $suggestions[] = [
                    'query' => "{$model} {$relationship} {$query}",
                    'description' => "Find {$model} {$relationship} {$query}",
                    'confidence' => 0.6
                ];
            }
        }
        
        return $suggestions;
    }
}
