<?php

namespace Venmail\SemanticSearch\Data;

class ParsedQuery
{
    public string $original;
    public array $tokens;
    public array $entities;
    public array $actions;
    public array $filters;
    public array $relationships;
    public array $aggregations;
    public ?string $booleanOperator; // AND, OR
    public ?string $locale;
    
    public function __construct(
        string $original,
        array $tokens = [],
        array $entities = [],
        array $actions = [],
        array $filters = [],
        array $relationships = [],
        array $aggregations = [],
        ?string $booleanOperator = 'AND',
        ?string $locale = null
    ) {
        $this->original = $original;
        $this->tokens = $tokens;
        $this->entities = $entities;
        $this->actions = $actions;
        $this->filters = $filters;
        $this->relationships = $relationships;
        $this->aggregations = $aggregations;
        $this->booleanOperator = $booleanOperator;
        $this->locale = $locale;
    }
    
    public function getOriginal(): string
    {
        return $this->original;
    }
    
    public function getTokens(): array
    {
        return $this->tokens;
    }
    
    public function getEntities(): array
    {
        return $this->entities;
    }
    
    public function getActions(): array
    {
        return $this->actions;
    }
    
    public function getFilters(): array
    {
        return $this->filters;
    }
    
    public function getRelationships(): array
    {
        return $this->relationships;
    }
    
    public function getLocale(): ?string
    {
        return $this->locale;
    }
    
    public function hasEntities(): bool
    {
        return !empty($this->entities);
    }
    
    public function hasFilters(): bool
    {
        return !empty($this->filters);
    }
    
    public function hasRelationships(): bool
    {
        return !empty($this->relationships);
    }
    
    public function getAggregations(): array
    {
        return $this->aggregations;
    }
    
    public function hasAggregations(): bool
    {
        return !empty($this->aggregations);
    }
    
    public function getBooleanOperator(): ?string
    {
        return $this->booleanOperator;
    }
}


