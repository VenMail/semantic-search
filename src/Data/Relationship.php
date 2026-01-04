<?php

namespace Venmail\SemanticSearch\Data;

class Relationship
{
    public string $from;
    public string $to;
    public ?string $mapping;
    public ?string $type;
    public float $confidence;
    public ?string $locale;
    
    public function __construct(
        string $from,
        string $to,
        ?string $mapping = null,
        ?string $type = null,
        float $confidence = 1.0,
        ?string $locale = null
    ) {
        $this->from = $from;
        $this->to = $to;
        $this->mapping = $mapping;
        $this->type = $type;
        $this->confidence = $confidence;
        $this->locale = $locale;
    }
    
    public function getFrom(): string
    {
        return $this->from;
    }
    
    public function getTo(): string
    {
        return $this->to;
    }
    
    public function getMapping(): ?string
    {
        return $this->mapping;
    }
    
    public function getType(): ?string
    {
        return $this->type;
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


