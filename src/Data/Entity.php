<?php

namespace Venmail\SemanticSearch\Data;

class Entity
{
    public string $type;
    public string $value;
    public ?string $mapping;
    public float $confidence;
    public ?string $locale;
    
    public function __construct(
        string $type,
        string $value,
        ?string $mapping = null,
        float $confidence = 1.0,
        ?string $locale = null
    ) {
        $this->type = $type;
        $this->value = $value;
        $this->mapping = $mapping;
        $this->confidence = $confidence;
        $this->locale = $locale;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function getValue(): string
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



