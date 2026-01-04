<?php

namespace Venmail\SemanticSearch\Core;

class ResolvedField
{
    public string $field;
    public string $type;
    public bool $indexed;
    public bool $nullable;
    public ?string $indexName;
    
    public function __construct(
        string $field,
        string $type = 'string',
        bool $indexed = false,
        bool $nullable = false,
        ?string $indexName = null
    ) {
        $this->field = $field;
        $this->type = $type;
        $this->indexed = $indexed;
        $this->nullable = $nullable;
        $this->indexName = $indexName;
    }
    
    public function getField(): string
    {
        return $this->field;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function isIndexed(): bool
    {
        return $this->indexed;
    }
    
    public function isNullable(): bool
    {
        return $this->nullable;
    }
    
    public function getIndexName(): ?string
    {
        return $this->indexName;
    }
    
    public function isDateType(): bool
    {
        return in_array($this->type, ['date', 'datetime', 'timestamp'], true);
    }
    
    public function isTimeType(): bool
    {
        return $this->type === 'time';
    }
    
    public function isNumericType(): bool
    {
        return in_array($this->type, ['integer', 'decimal'], true);
    }
}

