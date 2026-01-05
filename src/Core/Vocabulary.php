<?php

namespace Venmail\SemanticSearch\Core;

class Vocabulary
{
    private array $modelMappings = [];
    private array $fieldMappings = [];
    private array $actionMappings = [];
    private array $relationshipMappings = [];
    private array $businessTerms = [];
    private array $fieldSynonyms = [];
    
    public function addModelMapping(string $term, string $modelClass, array $variations = []): void
    {
        $this->modelMappings[$term] = [
            'class' => $modelClass,
            'variations' => $variations,
        ];
        
        // Add variations
        foreach ($variations as $variation) {
            $this->modelMappings[$variation] = [
                'class' => $modelClass,
                'variations' => $variations,
            ];
        }
    }
    
    public function addFieldMapping(string $model, string $field, string $type, array $synonyms = []): void
    {
        $key = "{$model}.{$field}";
        $this->fieldMappings[$key] = [
            'model' => $model,
            'field' => $field,
            'type' => $type,
            'synonyms' => $synonyms,
        ];
        
        foreach ($synonyms as $synonym) {
            $this->fieldSynonyms[$synonym] = $key;
        }
    }
    
    public function addActionMapping(string $term, string $action, array $variations = []): void
    {
        $this->actionMappings[$term] = [
            'action' => $action,
            'variations' => $variations,
        ];
        
        foreach ($variations as $variation) {
            $this->actionMappings[$variation] = [
                'action' => $action,
                'variations' => $variations,
            ];
        }
    }
    
    public function addRelationshipMapping(string $from, string $to, string $type, string $method): void
    {
        $key = "{$from}:{$to}";
        $this->relationshipMappings[$key] = [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'method' => $method,
        ];
    }
    
    public function addBusinessTerm(string $term, array $associations = []): void
    {
        $this->businessTerms[$term] = $associations;
    }
    
    public function isModel(string $term): bool
    {
        return isset($this->modelMappings[strtolower($term)]);
    }
    
    public function getModelMapping(string $term): ?array
    {
        return $this->modelMappings[strtolower($term)] ?? null;
    }
    
    public function isField(string $term): bool
    {
        return isset($this->fieldSynonyms[strtolower($term)]);
    }
    
    public function getFieldMapping(string $term): ?array
    {
        $synonym = $this->fieldSynonyms[strtolower($term)] ?? null;
        return $synonym ? ($this->fieldMappings[$synonym] ?? null) : null;
    }
    
    public function isAction(string $term): bool
    {
        return isset($this->actionMappings[strtolower($term)]);
    }
    
    public function getActionMapping(string $term): ?array
    {
        return $this->actionMappings[strtolower($term)] ?? null;
    }
    
    public function getRelationshipMapping(string $from, string $to): ?array
    {
        $key = "{$from}:{$to}";
        return $this->relationshipMappings[$key] ?? null;
    }
    
    public function getBusinessTerms(): array
    {
        return $this->businessTerms;
    }
    
    public function getAllModelNames(): array
    {
        return array_keys($this->modelMappings);
    }
    
    public function getAllFieldNames(): array
    {
        return array_keys($this->fieldSynonyms);
    }
    
    public function getAllActionWords(): array
    {
        return array_keys($this->actionMappings);
    }
}



