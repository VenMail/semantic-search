<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Venmail\SemanticSearch\Core\FieldResolver;
use Venmail\SemanticSearch\Core\ResolvedField;
use Venmail\SemanticSearch\Core\SchemaAnalyzer;
use Venmail\SemanticSearch\Data\Entity;
use Venmail\SemanticSearch\Data\Filter;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\ProjectMetadata;
use Venmail\SemanticSearch\Data\Relationship;

class ContextualQueryBuilder
{
    private SchemaAnalyzer $schemaAnalyzer;
    private FieldResolver $fieldResolver;
    
    public function __construct(SchemaAnalyzer $schemaAnalyzer)
    {
        $this->schemaAnalyzer = $schemaAnalyzer;
    }
    public function buildQuery(ParsedQuery $parsedQuery, ProjectMetadata $metadata): Builder
    {
        if (!$parsedQuery->hasEntities()) {
            throw new \InvalidArgumentException('Query must contain at least one entity');
        }
        
        // Get primary entity
        $primaryEntity = $parsedQuery->getEntities()[0];
        $modelClass = $primaryEntity->getMapping();
        
        if (!$modelClass || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            throw new \InvalidArgumentException("Invalid model class: {$modelClass}");
        }
        
        // Initialize field resolver with metadata
        $this->fieldResolver = new FieldResolver($this->schemaAnalyzer, $metadata);
        
        // Start building query
        $query = $modelClass::query();
        
        // Apply relationships (with intelligent mapping)
        $query = $this->applyRelationships($query, $parsedQuery, $metadata);
        
        // Apply filters (with intelligent field resolution and index awareness)
        $query = $this->applyFilters($query, $parsedQuery, $metadata);
        
        // Apply scopes based on business logic
        $query = $this->applyBusinessLogic($query, $parsedQuery, $metadata);
        
        return $query;
    }
    
    private function applyRelationships(Builder $query, ParsedQuery $parsedQuery, ProjectMetadata $metadata): Builder
    {
        foreach ($parsedQuery->getRelationships() as $relationship) {
            $relationshipMapping = $this->findRelationshipMapping($relationship, $metadata);
            
            if ($relationshipMapping) {
                $query->whereHas($relationshipMapping['method'], function ($q) use ($relationship, $parsedQuery, $metadata) {
                    // Apply any filters on the related model
                    $this->applyRelationshipFilters($q, $relationship, $parsedQuery, $metadata);
                });
            }
        }
        
        return $query;
    }
    
    private function applyFilters(Builder $query, ParsedQuery $parsedQuery, ProjectMetadata $metadata): Builder
    {
        // Resolve all filters with intelligent field resolution
        $resolvedFilters = [];
        foreach ($parsedQuery->getFilters() as $filter) {
            $resolved = $this->fieldResolver->resolveField($filter, $query->getModel());
            if ($resolved) {
                $resolvedFilters[] = [
                    'filter' => $filter,
                    'resolved' => $resolved,
                ];
            }
        }
        
        // Group by resolved field
        $filterGroups = [];
        foreach ($resolvedFilters as $item) {
            $field = $item['resolved']->getField();
            if (!isset($filterGroups[$field])) {
                $filterGroups[$field] = [
                    'resolved' => $item['resolved'],
                    'filters' => [],
                ];
            }
            $filterGroups[$field]['filters'][] = $item['filter'];
        }
        
        // Sort by index priority (indexed fields first for better performance)
        uasort($filterGroups, function ($a, $b) {
            $aIndexed = $a['resolved']->isIndexed() ? 1 : 0;
            $bIndexed = $b['resolved']->isIndexed() ? 1 : 0;
            return $bIndexed <=> $aIndexed;
        });
        
        // Apply grouped filters
        foreach ($filterGroups as $field => $group) {
            $resolved = $group['resolved'];
            $filters = $group['filters'];
            
            // Handle date/datetime fields
            if ($resolved->isDateType()) {
                $dateFilters = $this->groupDateFilters($filters, $query->getModel(), $resolved);
                if (!empty($dateFilters)) {
                    if (isset($dateFilters['is_datetime']) && $dateFilters['is_datetime']) {
                        $query->whereBetween($dateFilters['datetime_field'], [
                            $dateFilters['from'],
                            $dateFilters['to']
                        ]);
                    } elseif (isset($dateFilters['from']) && isset($dateFilters['to'])) {
                        $query->whereBetween($field, [$dateFilters['from'], $dateFilters['to']]);
                    } elseif (isset($dateFilters['from'])) {
                        $query->where($field, '>=', $dateFilters['from']);
                    } elseif (isset($dateFilters['to'])) {
                        $query->where($field, '<=', $dateFilters['to']);
                    }
                    continue;
                }
            }
            
            // Handle time fields
            if ($resolved->isTimeType()) {
                $timeFilters = [];
                foreach ($filters as $filter) {
                    $timeFilters[] = $filter;
                }
                if (count($timeFilters) === 2) {
                    $fromFilter = null;
                    $toFilter = null;
                    foreach ($timeFilters as $tf) {
                        if ($tf->getOperator() === '>=' || $tf->getOperator() === '>') {
                            $fromFilter = $tf;
                        } elseif ($tf->getOperator() === '<=' || $tf->getOperator() === '<') {
                            $toFilter = $tf;
                        }
                    }
                    if ($fromFilter && $toFilter) {
                        $query->whereBetween($field, [$fromFilter->getValue(), $toFilter->getValue()]);
                        continue;
                    }
                }
            }
            
            // Apply other filters with type-aware handling
            foreach ($filters as $filter) {
                $operator = $this->normalizeOperator($filter->getOperator());
                $value = $this->normalizeValueByType($filter->getValue(), $resolved->getType());
                
                // Use indexed field for better performance
                if ($resolved->isIndexed() && $operator === '=') {
                    // Indexed equality - most efficient
                    $query->where($field, '=', $value);
                } else {
                    // Apply operator
                    switch ($operator) {
                        case '>':
                            $query->where($field, '>', $value);
                            break;
                        case '<':
                            $query->where($field, '<', $value);
                            break;
                        case '>=':
                            $query->where($field, '>=', $value);
                            break;
                        case '<=':
                            $query->where($field, '<=', $value);
                            break;
                        case '!=':
                        case '<>':
                            $query->where($field, '!=', $value);
                            break;
                        case '=':
                        default:
                            $query->where($field, '=', $value);
                            break;
                    }
                }
            }
        }
        
        return $query;
    }
    
    private function normalizeValueByType(mixed $value, string $type): mixed
    {
        switch ($type) {
            case 'integer':
                return is_numeric($value) ? (int)$value : $value;
            case 'decimal':
                return is_numeric($value) ? (float)$value : $value;
            case 'boolean':
                if (is_string($value)) {
                    $lower = strtolower($value);
                    return in_array($lower, ['true', '1', 'yes', 'on'], true);
                }
                return (bool)$value;
            case 'date':
            case 'datetime':
            case 'timestamp':
                // Already normalized by DatePhraseParser
                return $value;
            default:
                return $value;
        }
    }
    
    private function groupDateFilters(array $filters, Model $model = null, ?ResolvedField $resolvedField = null): array
    {
        $dateRange = [];
        $timeRange = [];
        
        foreach ($filters as $filter) {
            $field = $filter->getField();
            $operator = $filter->getOperator();
            $value = $filter->getValue();
            
            // Group date filters (>= and <= become range)
            if ($operator === '>=' || $operator === '>') {
                $dateRange['from'] = $value;
            } elseif ($operator === '<=' || $operator === '<') {
                $dateRange['to'] = $value;
            } elseif ($operator === '=') {
                // Single date match
                $dateRange['from'] = $value;
                $dateRange['to'] = $value;
            }
            
            // Check if this might be a time component (H:i:s format)
            if (is_string($value) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
                if ($operator === '>=' || $operator === '>') {
                    $timeRange['from'] = $value;
                } elseif ($operator === '<=' || $operator === '<') {
                    $timeRange['to'] = $value;
                }
            }
        }
        
        // If we have both date and time, and it's the same day, combine them
        if (!empty($dateRange) && !empty($timeRange) && 
            isset($dateRange['from']) && isset($dateRange['to']) &&
            $dateRange['from'] === $dateRange['to'] && $model) {
            // Same day with time range - use whereBetween on datetime field
            $dateTimeFrom = $dateRange['from'] . ' ' . ($timeRange['from'] ?? '00:00:00');
            $dateTimeTo = $dateRange['to'] . ' ' . ($timeRange['to'] ?? '23:59:59');
            
            // Find datetime field (created_at, updated_at, or any timestamp field)
            $datetimeField = $this->findDateTimeField($filters, $model);
            
            if ($datetimeField) {
                return [
                    'datetime_field' => $datetimeField,
                    'from' => $dateTimeFrom,
                    'to' => $dateTimeTo,
                    'is_datetime' => true
                ];
            }
        }
        
        return $dateRange;
    }
    
    private function findDateTimeField(array $filters, Model $model): ?string
    {
        $modelClass = get_class($model);
        $schema = $this->schemaAnalyzer->getModelSchema($modelClass);
        $columns = $schema['columns'] ?? [];
        
        // Priority: created_at, updated_at, then any datetime column
        $priorityFields = ['created_at', 'updated_at'];
        
        foreach ($priorityFields as $field) {
            if (isset($columns[$field])) {
                $type = $columns[$field]['type'] ?? '';
                if (in_array($type, ['datetime', 'timestamp'], true)) {
                    return $field;
                }
            }
        }
        
        // Find any datetime column
        foreach ($columns as $field => $info) {
            $type = $info['type'] ?? '';
            if (in_array($type, ['datetime', 'timestamp'], true)) {
                return $field;
            }
        }
        
        return null;
    }
    
    private function applyBusinessLogic(Builder $query, ParsedQuery $parsedQuery, ProjectMetadata $metadata): Builder
    {
        $modelClass = get_class($query->getModel());
        $modelData = $metadata->getModels()[$modelClass] ?? [];
        
        // Apply scopes based on query context
        $tokens = $parsedQuery->getTokens();
        
        foreach ($modelData['scopes'] ?? [] as $scopeName => $scopeData) {
            if (in_array($scopeName, $tokens, true)) {
                $scopeMethod = 'scope' . ucfirst($scopeName);
                if (method_exists($query->getModel(), $scopeMethod)) {
                    $query->{$scopeName}();
                }
            }
        }
        
        return $query;
    }
    
    private function findRelationshipMapping(Relationship $relationship, ProjectMetadata $metadata): ?array
    {
        $fromModel = $relationship->getFrom();
        $toModel = $relationship->getTo();
        
        // Find the model class
        $fromModelClass = $this->findModelClass($fromModel, $metadata);
        $toModelClass = $this->findModelClass($toModel, $metadata);
        
        if (!$fromModelClass || !$toModelClass) {
            return null;
        }
        
        $modelData = $metadata->getModels()[$fromModelClass] ?? [];
        $relationships = $modelData['relationships'] ?? [];
        
        // Intelligent relationship matching using static analysis results
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($relationships as $relName => $relData) {
            $score = $this->calculateRelationshipMatchScore(
                $relName,
                $relData,
                $toModel,
                $toModelClass,
                $fromModelClass
            );
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'method' => $relName,
                    'type' => $relData['type'] ?? 'hasMany',
                    'target' => $relData['target'] ?? null,
                    'score' => $score,
                ];
            }
        }
        
        // Only return if confidence is high enough
        return $bestScore >= 0.6 ? $bestMatch : null;
    }
    
    private function calculateRelationshipMatchScore(
        string $relName,
        array $relData,
        string $toModel,
        string $toModelClass,
        string $fromModelClass
    ): float
    {
        $score = 0.0;
        $toModelBase = class_basename($toModelClass);
        $relNameLower = strtolower($relName);
        $toModelLower = strtolower($toModel);
        $toModelBaseLower = strtolower($toModelBase);
        
        // Exact match on relationship name
        if ($relNameLower === $toModelLower || $relNameLower === $toModelBaseLower) {
            $score += 1.0;
        }
        
        // Contains model name
        if (str_contains($relNameLower, $toModelLower) || str_contains($relNameLower, $toModelBaseLower)) {
            $score += 0.7;
        }
        
        // Check if target model matches (from static analysis)
        if (isset($relData['target']) && $relData['target'] === $toModelClass) {
            $score += 0.9;
        }
        
        // Relationship type scoring (hasMany > belongsTo for "with" patterns)
        $type = $relData['type'] ?? '';
        if (in_array($type, ['hasMany', 'hasOne'], true)) {
            $score += 0.2;
        }
        
        return min($score, 1.0);
    }
    
    private function findModelClass(string $modelName, ProjectMetadata $metadata): ?string
    {
        // Try exact match first
        foreach ($metadata->getModels() as $modelClass => $data) {
            if (strtolower(class_basename($modelClass)) === strtolower($modelName)) {
                return $modelClass;
            }
        }
        
        // Try partial match
        foreach ($metadata->getModels() as $modelClass => $data) {
            if (stripos(class_basename($modelClass), $modelName) !== false) {
                return $modelClass;
            }
        }
        
        return null;
    }
    
    
    private function normalizeOperator(string $operator): string
    {
        $normalized = strtolower(trim($operator));
        
        $mapping = [
            'older' => '>',
            'younger' => '<',
            'over' => '>',
            'under' => '<',
            'above' => '>',
            'below' => '<',
            'equals' => '=',
            'equal' => '=',
        ];
        
        return $mapping[$normalized] ?? $operator;
    }
    
    private function applyRelationshipFilters(Builder $query, Relationship $relationship, ParsedQuery $parsedQuery, ProjectMetadata $metadata): void
    {
        // Get target model class for the relationship
        $targetModelName = $relationship->getTo();
        $targetModelClass = $this->findModelClass($targetModelName, $metadata);
        
        if (!$targetModelClass || !class_exists($targetModelClass) || !is_subclass_of($targetModelClass, Model::class)) {
            return;
        }
        
        // Get the related model instance to resolve fields
        $relatedModel = new $targetModelClass();
        
        // Extract filters that belong to the target model
        $relatedFilters = $this->extractFiltersForModel($parsedQuery->getFilters(), $targetModelClass, $metadata);
        
        if (empty($relatedFilters)) {
            return;
        }
        
        // Resolve and apply filters using the same logic as applyFilters
        $resolvedFilters = [];
        foreach ($relatedFilters as $filter) {
            $resolved = $this->fieldResolver->resolveField($filter, $relatedModel);
            if ($resolved) {
                $resolvedFilters[] = [
                    'filter' => $filter,
                    'resolved' => $resolved,
                ];
            }
        }
        
        // Group by resolved field
        $filterGroups = [];
        foreach ($resolvedFilters as $item) {
            $field = $item['resolved']->getField();
            if (!isset($filterGroups[$field])) {
                $filterGroups[$field] = [
                    'resolved' => $item['resolved'],
                    'filters' => [],
                ];
            }
            $filterGroups[$field]['filters'][] = $item['filter'];
        }
        
        // Sort by index priority
        uasort($filterGroups, function ($a, $b) {
            $aIndexed = $a['resolved']->isIndexed() ? 1 : 0;
            $bIndexed = $b['resolved']->isIndexed() ? 1 : 0;
            return $bIndexed <=> $aIndexed;
        });
        
        // Apply grouped filters
        foreach ($filterGroups as $field => $group) {
            $resolved = $group['resolved'];
            $filters = $group['filters'];
            
            // Handle date/datetime fields
            if ($resolved->isDateType()) {
                $dateFilters = $this->groupDateFilters($filters, $relatedModel, $resolved);
                if (!empty($dateFilters)) {
                    if (isset($dateFilters['is_datetime']) && $dateFilters['is_datetime']) {
                        $query->whereBetween($dateFilters['datetime_field'], [
                            $dateFilters['from'],
                            $dateFilters['to']
                        ]);
                    } elseif (isset($dateFilters['from']) && isset($dateFilters['to'])) {
                        $query->whereBetween($field, [$dateFilters['from'], $dateFilters['to']]);
                    } elseif (isset($dateFilters['from'])) {
                        $query->where($field, '>=', $dateFilters['from']);
                    } elseif (isset($dateFilters['to'])) {
                        $query->where($field, '<=', $dateFilters['to']);
                    }
                    continue;
                }
            }
            
            // Handle time fields
            if ($resolved->isTimeType()) {
                $timeFilters = [];
                foreach ($filters as $filter) {
                    $timeFilters[] = $filter;
                }
                if (count($timeFilters) === 2) {
                    $fromFilter = null;
                    $toFilter = null;
                    foreach ($timeFilters as $tf) {
                        if ($tf->getOperator() === '>=' || $tf->getOperator() === '>') {
                            $fromFilter = $tf;
                        } elseif ($tf->getOperator() === '<=' || $tf->getOperator() === '<') {
                            $toFilter = $tf;
                        }
                    }
                    if ($fromFilter && $toFilter) {
                        $query->whereBetween($field, [$fromFilter->getValue(), $toFilter->getValue()]);
                        continue;
                    }
                }
            }
            
            // Apply other filters with type-aware handling
            foreach ($filters as $filter) {
                $operator = $this->normalizeOperator($filter->getOperator());
                $value = $this->normalizeValueByType($filter->getValue(), $resolved->getType());
                
                if ($resolved->isIndexed() && $operator === '=') {
                    $query->where($field, '=', $value);
                } else {
                    switch ($operator) {
                        case '>':
                            $query->where($field, '>', $value);
                            break;
                        case '<':
                            $query->where($field, '<', $value);
                            break;
                        case '>=':
                            $query->where($field, '>=', $value);
                            break;
                        case '<=':
                            $query->where($field, '<=', $value);
                            break;
                        case '!=':
                        case '<>':
                            $query->where($field, '!=', $value);
                            break;
                        case '=':
                        default:
                            $query->where($field, '=', $value);
                            break;
                    }
                }
            }
        }
    }
    
    private function extractFiltersForModel(array $filters, string $targetModelClass, ProjectMetadata $metadata): array
    {
        $modelFilters = [];
        $targetModelBase = class_basename($targetModelClass);
        
        foreach ($filters as $filter) {
            $mapping = $filter->getMapping();
            
            // Check if filter mapping matches target model
            if ($mapping) {
                $parts = explode('.', $mapping);
                if (count($parts) === 2) {
                    $mappedModelClass = $parts[0];
                    // Check if mapped model matches target
                    if ($mappedModelClass === $targetModelClass || 
                        class_basename($mappedModelClass) === $targetModelBase) {
                        $modelFilters[] = $filter;
                        continue;
                    }
                }
            }
            
            // If no mapping, check if field exists on target model
            // This is a fallback for filters without explicit model mapping
            $fieldName = $filter->getField();
            $modelData = $metadata->getModels()[$targetModelClass] ?? [];
            $columns = $this->schemaAnalyzer->getModelSchema($targetModelClass)['columns'] ?? [];
            
            if (isset($columns[$fieldName]) || 
                isset($columns[\Illuminate\Support\Str::snake($fieldName)])) {
                // Field exists on target model, likely belongs to it
                $modelFilters[] = $filter;
            }
        }
        
        return $modelFilters;
    }
}

