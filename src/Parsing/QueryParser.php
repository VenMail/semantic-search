<?php

namespace Venmail\SemanticSearch\Parsing;

use Illuminate\Support\Str;
use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\Action;
use Venmail\SemanticSearch\Data\Entity;
use Venmail\SemanticSearch\Data\Filter;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Relationship;
use Venmail\SemanticSearch\Parsing\DatePhraseParser;

class QueryParser
{
    private Vocabulary $vocabulary;
    private array $comparators = ['>', '<', '>=', '<=', '=', '!=', 'older', 'younger', 'than', 'over', 'under'];
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }
    
    public function parse(string $query): ParsedQuery
    {
        $tokens = $this->tokenize($query);
        $entities = $this->extractEntities($tokens);
        $actions = $this->extractActions($tokens);
        $filters = $this->extractFilters($tokens);
        $relationships = $this->extractRelationships($tokens);
        
        return new ParsedQuery(
            original: $query,
            tokens: $tokens,
            entities: $entities,
            actions: $actions,
            filters: $filters,
            relationships: $relationships
        );
    }
    
    private function tokenize(string $query): array
    {
        // Normalize and tokenize the query
        $normalized = Str::lower($query);
        
        // Preserve quoted phrases so "customer success" stays together
        preg_match_all('/"([^"]+)"|[\p{L}\p{N}]+/u', $normalized, $matches);
        
        $tokens = array_map(function ($match) {
            return trim($match, '"');
        }, array_filter($matches[0]));
        
        return $tokens;
    }
    
    private function extractEntities(array $tokens): array
    {
        $entities = [];
        
        foreach ($tokens as $token) {
            if ($this->vocabulary->isModel($token)) {
                $mapping = $this->vocabulary->getModelMapping($token);
                $entities[] = new Entity(
                    type: 'model',
                    value: $token,
                    mapping: $mapping['class'] ?? null,
                    confidence: 1.0
                );
            }
        }
        
        return $entities;
    }
    
    private function extractActions(array $tokens): array
    {
        $actions = [];
        
        foreach ($tokens as $token) {
            if ($this->vocabulary->isAction($token)) {
                $mapping = $this->vocabulary->getActionMapping($token);
                $actions[] = new Action(
                    type: 'action',
                    value: $token,
                    mapping: $mapping['action'] ?? null,
                    confidence: 1.0
                );
            }
        }
        
        return $actions;
    }
    
    private function extractFilters(array $tokens): array
    {
        $filters = [];
        $query = implode(' ', $tokens);
        
        // Extract date ranges from natural language
        $dateFilters = $this->extractDateFilters($query);
        $filters = array_merge($filters, $dateFilters);
        
        // Look for filter patterns (e.g., "older than 25", "amount > 1000")
        for ($i = 0; $i < count($tokens); $i++) {
            if ($this->isComparator($tokens[$i])) {
                $field = $tokens[$i - 1] ?? null;
                $value = $tokens[$i + 1] ?? null;
                
                if ($field && $value) {
                    $operator = $this->normalizeOperator($tokens[$i]);
                    $normalizedValue = $this->normalizeValue($value);
                    
                    $fieldMapping = null;
                    if ($this->vocabulary->isField($field)) {
                        $fieldMapping = $this->vocabulary->getFieldMapping($field);
                    }
                    
                    $filters[] = new Filter(
                        field: $field,
                        operator: $operator,
                        value: $normalizedValue,
                        mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                        confidence: 0.8
                    );
                }
            }
            
            // Handle "older than", "younger than" patterns
            if (($tokens[$i] === 'older' || $tokens[$i] === 'younger') && 
                isset($tokens[$i + 1]) && $tokens[$i + 1] === 'than' &&
                isset($tokens[$i + 2])) {
                $field = 'age'; // Default to age, could be improved
                $operator = $tokens[$i] === 'older' ? '>' : '<';
                $value = $tokens[$i + 2];
                
                $filters[] = new Filter(
                    field: $field,
                    operator: $operator,
                    value: $this->normalizeValue($value),
                    mapping: null,
                    confidence: 0.7
                );
            }
            
            // Handle "over", "under", "above", "below" with numeric values
            if (in_array($tokens[$i], ['over', 'under', 'above', 'below']) && isset($tokens[$i + 1])) {
                $field = $tokens[$i - 1] ?? null;
                $operator = in_array($tokens[$i], ['over', 'above']) ? '>' : '<';
                $value = $tokens[$i + 1];
                
                // Only create filter if field is recognized or value is numeric
                if ($field && ($this->vocabulary->isField($field) || is_numeric($value))) {
                    $filters[] = new Filter(
                        field: $field,
                        operator: $operator,
                        value: $this->normalizeValue($value),
                        mapping: $this->vocabulary->isField($field) ? 
                            ($this->vocabulary->getFieldMapping($field)['model'] ?? null) . '.' . $field : null,
                        confidence: 0.8
                    );
                }
            }
        }
        
        return $filters;
    }
    
    private function extractDateFilters(string $query): array
    {
        $filters = [];
        
        // Use sophisticated DatePhraseParser
        $dateRange = DatePhraseParser::parse($query);
        
        if ($dateRange) {
            // Use generic date field name - will be resolved by ContextualQueryBuilder
            // based on actual model fields
            $dateField = 'date'; // Generic placeholder, resolved later
            
            if (isset($dateRange['from']) && isset($dateRange['to'])) {
                // Date range
                if ($dateRange['from'] === $dateRange['to']) {
                    // Single date
                    $filters[] = new Filter(
                        field: $dateField,
                        operator: '=',
                        value: $dateRange['from'],
                        mapping: null,
                        confidence: 0.95
                    );
                } else {
                    // Date range - will be grouped by ContextualQueryBuilder
                    $filters[] = new Filter(
                        field: $dateField,
                        operator: '>=',
                        value: $dateRange['from'],
                        mapping: null,
                        confidence: 0.95
                    );
                    
                    $filters[] = new Filter(
                        field: $dateField,
                        operator: '<=',
                        value: $dateRange['to'],
                        mapping: null,
                        confidence: 0.95
                    );
                }
                
                // Handle time range if present
                if (isset($dateRange['time']) && isset($dateRange['time']['from']) && isset($dateRange['time']['to'])) {
                    $timeField = 'time'; // Generic placeholder, resolved later
                    
                    $filters[] = new Filter(
                        field: $timeField,
                        operator: '>=',
                        value: $dateRange['time']['from'],
                        mapping: null,
                        confidence: 0.9
                    );
                    
                    $filters[] = new Filter(
                        field: $timeField,
                        operator: '<=',
                        value: $dateRange['time']['to'],
                        mapping: null,
                        confidence: 0.9
                    );
                }
            }
        }
        
        return $filters;
    }
    
    private function extractRelationships(array $tokens): array
    {
        $relationships = [];
        
        // Look for relationship patterns like "users with posts"
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $current = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            
            // Pattern: "entity with entity"
            if ($next && ($tokens[$i + 1] === 'with' || $tokens[$i + 1] === 'and')) {
                $target = $tokens[$i + 2] ?? null;
                
                if ($this->vocabulary->isModel($current) && 
                    $target && $this->vocabulary->isModel($target)) {
                    $mapping = $this->vocabulary->getRelationshipMapping($current, $target);
                    
                    $relationships[] = new Relationship(
                        from: $current,
                        to: $target,
                        mapping: $mapping ? $mapping['method'] : null,
                        type: $mapping['type'] ?? null,
                        confidence: 0.8
                    );
                }
            }
            
            // Pattern: "entity entity" (compound like "customer orders")
            if ($this->vocabulary->isModel($current) && 
                $next && $this->vocabulary->isModel($next)) {
                $mapping = $this->vocabulary->getRelationshipMapping($current, $next);
                
                if ($mapping) {
                    $relationships[] = new Relationship(
                        from: $current,
                        to: $next,
                        mapping: $mapping['method'],
                        type: $mapping['type'],
                        confidence: 0.7
                    );
                }
            }
        }
        
        return $relationships;
    }
    
    private function isComparator(string $token): bool
    {
        return in_array(strtolower($token), $this->comparators, true) ||
               in_array($token, ['>', '<', '>=', '<=', '=', '!='], true);
    }
    
    private function normalizeOperator(string $operator): string
    {
        $normalized = strtolower($operator);
        
        $mapping = [
            'older' => '>',
            'younger' => '<',
            'over' => '>',
            'under' => '<',
            'above' => '>',
            'below' => '<',
        ];
        
        return $mapping[$normalized] ?? $operator;
    }
    
    private function normalizeValue(mixed $value): mixed
    {
        // Try to convert to number if possible
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        // Handle date strings
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $value)) {
            return $value;
        }
        
        return $value;
    }
}

