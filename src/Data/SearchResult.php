<?php

namespace Venmail\SemanticSearch\Data;

class SearchResult
{
    public array $data;
    public ?array $pagination;
    public array $metadata;
    public float $executionTime;
    
    public function __construct(
        array $data,
        ?array $pagination = null,
        array $metadata = [],
        float $executionTime = 0.0
    ) {
        $this->data = $data;
        $this->pagination = $pagination;
        $this->metadata = $metadata;
        $this->executionTime = $executionTime;
    }
    
    public function getData(): array
    {
        return $this->data;
    }
    
    public function getPagination(): ?array
    {
        return $this->pagination;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }
    
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'pagination' => $this->pagination,
            'metadata' => $this->metadata,
            'execution_time' => $this->executionTime,
        ];
    }
}



