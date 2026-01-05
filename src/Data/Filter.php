<?php

namespace Venmail\SemanticSearch\Data;

class Filter
{
    public string $field;
    public string $operator;
    public mixed $value;
    public ?string $mapping;
    public float $confidence;
    public ?string $locale;
    
    public function __construct(
        string $field,
        string $operator,
        mixed $value,
        ?string $mapping = null,
        float $confidence = 1.0,
        ?string $locale = null
    ) {
        $this->field = $field;
        $this->operator = $operator;
        $this->value = $value;
        $this->mapping = $mapping;
        $this->confidence = $confidence;
        $this->locale = $locale;
    }
    
    public function getField(): string
    {
        return $this->field;
    }
    
    public function getOperator(): string
    {
        return $this->operator;
    }
    
    public function getValue(): mixed
    {
        return $this->value;
    }
    
    public function getMapping(): ?string
    {
        return $this->mapping;
    }
    
    public function getConfidence(): float
    {
        return $this->confidence;
    }
    
    public function getLocale(): ?string
    {
        return $this->locale;
    }
}



