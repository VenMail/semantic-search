<?php

namespace Venmail\SemanticSearch\Disambiguation;

class DisambiguationResult
{
    private string $originalQuery;
    private string $correctedQuery;
    private float $confidence;
    private bool $parseable;
    private ?string $failureReason;
    private array $suggestions;
    private string $source;
    private float $processingTime;
    
    public function __construct(
        string $originalQuery,
        string $correctedQuery = '',
        float $confidence = 1.0,
        bool $parseable = true,
        ?string $failureReason = null,
        array $suggestions = [],
        string $source = 'static',
        float $processingTime = 0.0
    ) {
        $this->originalQuery = $originalQuery;
        $this->correctedQuery = $correctedQuery ?: $originalQuery;
        $this->confidence = $confidence;
        $this->parseable = $parseable;
        $this->failureReason = $failureReason;
        $this->suggestions = $suggestions;
        $this->source = $source;
        $this->processingTime = $processingTime;
    }
    
    public function getOriginalQuery(): string
    {
        return $this->originalQuery;
    }
    
    public function getCorrectedQuery(): string
    {
        return $this->correctedQuery;
    }
    
    public function getConfidence(): float
    {
        return $this->confidence;
    }
    
    public function isParseable(): bool
    {
        return $this->parseable;
    }
    
    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }
    
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
    
    public function getSource(): string
    {
        return $this->source;
    }
    
    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }
    
    public function needsClarification(): bool
    {
        return $this->confidence < 0.6 && !empty($this->suggestions);
    }
}

