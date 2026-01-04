<?php

namespace Venmail\SemanticSearch\Data;

class Aggregation
{
    private string $type; // count, sum, avg, min, max
    private ?string $field;
    private ?string $alias;
    private ?string $groupBy;
    
    public function __construct(
        string $type,
        ?string $field = null,
        ?string $alias = null,
        ?string $groupBy = null
    ) {
        $this->type = $type;
        $this->field = $field;
        $this->alias = $alias;
        $this->groupBy = $groupBy;
    }
    
    public function setGroupBy(?string $groupBy): void
    {
        $this->groupBy = $groupBy;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function getField(): ?string
    {
        return $this->field;
    }
    
    public function getAlias(): ?string
    {
        return $this->alias;
    }
    
    public function getGroupBy(): ?string
    {
        return $this->groupBy;
    }
}

