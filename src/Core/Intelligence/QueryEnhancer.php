<?php

namespace Venmail\SemanticSearch\Core\Intelligence;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Core\Config\SemanticFieldPatterns;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Entity;
use Venmail\SemanticSearch\Data\Action;
use Venmail\SemanticSearch\Data\Relationship;

/**
 * Query Enhancer - Intelligently enhances parsed queries with additional context
 * 
 * This component analyzes parsed queries and adds missing entities, actions,
 * and relationships based on context, patterns, and project-specific knowledge.
 */
class QueryEnhancer
{
    private Vocabulary $vocabulary;
    private array $enhancementPatterns;
    private array $contextualMappings;
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
        $this->initializeEnhancementPatterns();
        $this->initializeContextualMappings();
    }
    
    /**
     * Main enhancement method
     */
    public function enhance(ParsedQuery $query): ParsedQuery
    {
        $enhanced = clone $query;
        
        // Apply various enhancement strategies
        $enhanced = $this->enhanceEntities($enhanced);
        $enhanced = $this->enhanceRelationships($enhanced);
        $enhanced = $this->enhanceTemporalContext($enhanced);
        $enhanced = $this->enhanceBusinessContext($enhanced);
        $enhanced = $this->enhanceUserIntent($enhanced);
        
        return $enhanced;
    }
    
    /**
     * Enhance entities with additional context
     */
    private function enhanceEntities(ParsedQuery $query): ParsedQuery
    {
        $original = strtolower($query->getOriginal());
        $tokens = $query->getTokens();
        
        // Look for implicit entities
        foreach ($tokens as $token) {
            if ($this->isImplicitEntity($token)) {
                $existingEntities = array_filter($query->getEntities(), fn($e) => $e->value === $token);
                if (empty($existingEntities)) {
                    $query->entities[] = new Entity('implicit_model', $token, 0.7);
                }
            }
        }
        
        // Enhance entity confidence based on context
        foreach ($query->entities as $entity) {
            if ($entity->confidence < 0.8) {
                $boostedConfidence = $this->calculateEntityConfidence($entity, $original);
                if ($boostedConfidence > $entity->confidence) {
                    $entity->confidence = $boostedConfidence;
                }
            }
        }
        
        return $query;
    }
    
    /**
     * Enhance relationships with inferred connections
     */
    private function enhanceRelationships(ParsedQuery $query): ParsedQuery
    {
        $original = strtolower($query->getOriginal());
        
        // Look for implicit relationship indicators
        foreach ($this->enhancementPatterns['implicit_relationships'] as $pattern => $relationship) {
            if (preg_match($pattern, $original)) {
                $existingRels = array_filter($query->getRelationships(), fn($r) => $r->type === $relationship['type']);
                if (empty($existingRels)) {
                    $query->relationships[] = new Relationship(
                        $relationship['type'],
                        $relationship['target'] ?? 'unknown',
                        $relationship['confidence'] ?? 0.6
                    );
                }
            }
        }
        
        return $query;
    }
    
    /**
     * Enhance temporal context (time-based queries)
     */
    private function enhanceTemporalContext(ParsedQuery $query): ParsedQuery
    {
        $original = strtolower($query->getOriginal());
        
        // Detect temporal patterns
        foreach ($this->enhancementPatterns['temporal'] as $pattern => $context) {
            if (preg_match($pattern, $original, $matches)) {
                // Add temporal entity
                $query->entities[] = new Entity('temporal', $matches[0], 0.8);
                
                // Add temporal filter if not present
                $this->addTemporalFilter($query, $context, $matches);
            }
        }
        
        return $query;
    }
    
    /**
     * Enhance business context (business-specific patterns)
     */
    private function enhanceBusinessContext(ParsedQuery $query): ParsedQuery
    {
        $original = strtolower($query->getOriginal());
        
        // Look for business-specific patterns
        foreach ($this->enhancementPatterns['business'] as $pattern => $context) {
            if (preg_match($pattern, $original)) {
                $query->entities[] = new Entity('business_context', $context['type'], 0.7);
                
                // Add business-specific filters
                $this->addBusinessFilter($query, $context);
            }
        }
        
        return $query;
    }
    
    /**
     * Enhance user intent (what the user wants to achieve)
     */
    private function enhanceUserIntent(ParsedQuery $query): ParsedQuery
    {
        $original = strtolower($query->getOriginal());
        
        // Detect user intent patterns
        foreach ($this->enhancementPatterns['intent'] as $pattern => $intent) {
            if (preg_match($pattern, $original)) {
                // Add intent as action if not present
                $existingActions = array_filter($query->getActions(), fn($a) => $a->value === $intent['action']);
                if (empty($existingActions)) {
                    $query->actions[] = new Action($intent['action'], $intent['confidence'] ?? 0.8);
                }
            }
        }
        
        return $query;
    }
    
    /**
     * Check if token is an implicit entity
     */
    private function isImplicitEntity(string $token): bool
    {
        // Check against common model patterns
        $modelPatterns = SemanticFieldPatterns::getCommonFieldPatterns();
        
        foreach ($modelPatterns as $type => $config) {
            if (in_array($token, $config['patterns'])) {
                return true;
            }
        }
        
        // Check against vocabulary
        return $this->vocabulary->isModel($token) || 
               $this->vocabulary->isField($token) || 
               $this->vocabulary->isAction($token);
    }
    
    /**
     * Calculate enhanced entity confidence
     */
    private function calculateEntityConfidence(Entity $entity, string $original): float
    {
        $confidence = $entity->confidence;
        
        // Boost confidence based on position in query
        $position = strpos($original, $entity->value);
        if ($position !== false && $position < strlen($original) * 0.3) {
            $confidence += 0.1; // Early position boost
        }
        
        // Boost confidence based on surrounding context
        $contextWords = $this->getContextWords($original, $entity->value);
        foreach ($contextWords as $word) {
            if (in_array($word, ['show', 'list', 'find', 'get', 'all'])) {
                $confidence += 0.05;
            }
        }
        
        return min(1.0, $confidence);
    }
    
    /**
     * Get context words around an entity
     */
    private function getContextWords(string $original, string $entity): array
    {
        $words = explode(' ', $original);
        $context = [];
        
        foreach ($words as $index => $word) {
            if ($word === $entity) {
                // Get words before and after
                if ($index > 0) $context[] = $words[$index - 1];
                if ($index < count($words) - 1) $context[] = $words[$index + 1];
                break;
            }
        }
        
        return $context;
    }
    
    /**
     * Add temporal filter to query
     */
    private function addTemporalFilter(ParsedQuery $query, array $context, array $matches): void
    {
        $filter = [
            'field' => $context['field'] ?? 'created_at',
            'operator' => $context['operator'] ?? '>=',
            'value' => $this->parseTemporalValue($context, $matches),
            'type' => 'temporal'
        ];
        
        $query->filters[] = $filter;
    }
    
    /**
     * Add business filter to query
     */
    private function addBusinessFilter(ParsedQuery $query, array $context): void
    {
        $filter = [
            'field' => $context['field'] ?? 'status',
            'operator' => $context['operator'] ?? '=',
            'value' => $context['value'] ?? 'active',
            'type' => 'business'
        ];
        
        $query->filters[] = $filter;
    }
    
    /**
     * Parse temporal value from matches
     */
    private function parseTemporalValue(array $context, array $matches): string
    {
        if (isset($context['value_parser'])) {
            return call_user_func($context['value_parser'], $matches);
        }
        
        return $matches[0] ?? 'now';
    }
    
    /**
     * Initialize enhancement patterns
     */
    private function initializeEnhancementPatterns(): void
    {
        $this->enhancementPatterns = [
            'implicit_relationships' => [
                '/\b(\w+)\s+owned\s+by\s+(\w+)\b/' => [
                    'type' => 'owned_by',
                    'confidence' => 0.8
                ],
                '/\b(\w+)\s+created\s+by\s+(\w+)\b/' => [
                    'type' => 'created_by',
                    'confidence' => 0.8
                ],
                '/\b(\w+)\s+assigned\s+to\s+(\w+)\b/' => [
                    'type' => 'assigned_to',
                    'confidence' => 0.8
                ]
            ],
            'temporal' => [
                '/\b(\d+)\s+(days?|weeks?|months?|years?)\s+ago\b/' => [
                    'field' => 'created_at',
                    'operator' => '>=',
                    'value_parser' => fn($matches) => now()->sub("{$matches[2]} {$matches[1]}")
                ],
                '/\blast\s+(day|week|month|year)\b/' => [
                    'field' => 'created_at',
                    'operator' => '>=',
                    'value_parser' => fn($matches) => now()->sub("1 {$matches[1]}")
                ],
                '/\bthis\s+(day|week|month|year)\b/' => [
                    'field' => 'created_at',
                    'operator' => '>=',
                    'value_parser' => fn($matches) => now()->startOf("{$matches[1]}")
                ]
            ],
            'business' => [
                '/\b(active|inactive|pending|completed|cancelled)\b/' => [
                    'type' => 'status',
                    'field' => 'status',
                    'operator' => '=',
                    'value' => fn($matches) => $matches[0]
                ],
                '/\b(urgent|high|medium|low)\s+priority\b/' => [
                    'type' => 'priority',
                    'field' => 'priority',
                    'operator' => '=',
                    'value' => fn($matches) => $matches[0]
                ]
            ],
            'intent' => [
                '/\b(show|list|display)\b/' => [
                    'action' => 'list',
                    'confidence' => 0.9
                ],
                '/\b(find|search|look\s+for)\b/' => [
                    'action' => 'search',
                    'confidence' => 0.9
                ],
                '/\b(create|add|new)\b/' => [
                    'action' => 'create',
                    'confidence' => 0.9
                ],
                '/\b(update|edit|modify|change)\b/' => [
                    'action' => 'update',
                    'confidence' => 0.9
                ],
                '/\b(delete|remove|destroy)\b/' => [
                    'action' => 'delete',
                    'confidence' => 0.9
                ]
            ]
        ];
    }
    
    /**
     * Initialize contextual mappings
     */
    private function initializeContextualMappings(): void
    {
        $this->contextualMappings = [
            'common_synonyms' => [
                'show' => ['list', 'display', 'view'],
                'find' => ['search', 'look', 'locate'],
                'create' => ['add', 'new', 'make'],
                'update' => ['edit', 'modify', 'change'],
                'delete' => ['remove', 'destroy', 'eliminate']
            ],
            'field_mappings' => [
                'time' => ['created_at', 'updated_at', 'timestamp'],
                'date' => ['created_at', 'updated_at', 'date'],
                'status' => ['state', 'condition', 'phase'],
                'user' => ['user_id', 'owner_id', 'creator_id']
            ]
        ];
    }
    
    /**
     * Get enhancement statistics
     */
    public function getEnhancementStats(ParsedQuery $original, ParsedQuery $enhanced): array
    {
        return [
            'entities_added' => count($enhanced->getEntities()) - count($original->getEntities()),
            'actions_added' => count($enhanced->getActions()) - count($original->getActions()),
            'relationships_added' => count($enhanced->getRelationships()) - count($original->getRelationships()),
            'filters_added' => count($enhanced->getFilters()) - count($original->getFilters()),
            'total_enhancements' => $this->calculateTotalEnhancements($original, $enhanced)
        ];
    }
    
    private function calculateTotalEnhancements(ParsedQuery $original, ParsedQuery $enhanced): int
    {
        return (count($enhanced->getEntities()) - count($original->getEntities())) +
               (count($enhanced->getActions()) - count($original->getActions())) +
               (count($enhanced->getRelationships()) - count($original->getRelationships())) +
               (count($enhanced->getFilters()) - count($original->getFilters()));
    }
}
