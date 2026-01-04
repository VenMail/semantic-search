<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Support\Str;
use Venmail\SemanticSearch\Data\ProjectMetadata;

class DynamicVocabularyBuilder
{
    public function buildVocabulary(ProjectMetadata $metadata): Vocabulary
    {
        $vocabulary = new Vocabulary();
        
        // Build model mappings
        $this->buildModelMappings($vocabulary, $metadata);
        
        // Build field mappings
        $this->buildFieldMappings($vocabulary, $metadata);
        
        // Build action mappings
        $this->buildActionMappings($vocabulary, $metadata);
        
        // Build relationship mappings
        $this->buildRelationshipMappings($vocabulary, $metadata);
        
        // Build business terms
        $this->buildBusinessTerms($vocabulary, $metadata);
        
        return $vocabulary;
    }
    
    private function buildModelMappings(Vocabulary $vocabulary, ProjectMetadata $metadata): void
    {
        foreach ($metadata->getModels() as $modelClass => $data) {
            $className = class_basename($modelClass);
            $baseName = strtolower($className);
            
            // Map class name variations
            $variations = [
                $baseName,
                Str::plural($baseName),
                Str::snake($className),
                Str::kebab($className),
                Str::camel($className),
            ];
            
            $vocabulary->addModelMapping($baseName, $modelClass, $variations);
        }
    }
    
    private function buildFieldMappings(Vocabulary $vocabulary, ProjectMetadata $metadata): void
    {
        foreach ($metadata->getModels() as $modelClass => $modelData) {
            $className = class_basename($modelClass);
            
            // Map attributes
            foreach ($modelData['attributes'] ?? [] as $field => $type) {
                $fieldLower = strtolower($field);
                $synonyms = [
                    $fieldLower,
                    Str::snake($field),
                    Str::kebab($field),
                ];
                
                $vocabulary->addFieldMapping($modelClass, $field, $type, $synonyms);
            }
            
            // Map computed attributes
            foreach ($modelData['computed'] ?? [] as $computed => $data) {
                $computedLower = strtolower($computed);
                $synonyms = [
                    $computedLower,
                    Str::snake($computed),
                    Str::kebab($computed),
                ];
                
                $vocabulary->addFieldMapping($modelClass, $computed, 'computed', $synonyms);
            }
        }
    }
    
    private function buildActionMappings(Vocabulary $vocabulary, ProjectMetadata $metadata): void
    {
        // Common action words
        $actions = [
            'show' => ['display', 'list', 'get', 'fetch'],
            'find' => ['search', 'lookup', 'locate'],
            'create' => ['add', 'new', 'make'],
            'update' => ['edit', 'change', 'modify'],
            'delete' => ['remove', 'destroy'],
            'count' => ['total', 'number'],
            'sum' => ['total', 'add'],
            'average' => ['avg', 'mean'],
        ];
        
        foreach ($actions as $action => $variations) {
            $vocabulary->addActionMapping($action, $action, $variations);
        }
        
        // Extract actions from controller methods
        foreach ($metadata->getControllers() as $controller => $data) {
            foreach ($data['methods'] ?? [] as $method) {
                $methodName = strtolower($method['name']);
                
                // Map common controller method patterns
                if (in_array($methodName, ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'])) {
                    $vocabulary->addActionMapping($methodName, $methodName, []);
                }
            }
        }
    }
    
    private function buildRelationshipMappings(Vocabulary $vocabulary, ProjectMetadata $metadata): void
    {
        foreach ($metadata->getModels() as $modelClass => $modelData) {
            foreach ($modelData['relationships'] ?? [] as $relName => $relData) {
                // Try to infer target model from relationship name
                $targetModel = $this->inferTargetModel($relName, $metadata);
                
                if ($targetModel) {
                    $vocabulary->addRelationshipMapping(
                        $modelClass,
                        $targetModel,
                        $relData['type'] ?? 'hasMany',
                        $relName
                    );
                }
            }
        }
    }
    
    private function buildBusinessTerms(Vocabulary $vocabulary, ProjectMetadata $metadata): void
    {
        $terms = [];
        
        // Extract from views
        foreach ($metadata->getViews() as $view => $data) {
            $terms = array_merge($terms, $data['businessTerms'] ?? []);
        }
        
        // Extract from model scopes
        foreach ($metadata->getModels() as $modelClass => $modelData) {
            foreach ($modelData['scopes'] ?? [] as $scopeName => $scopeData) {
                $terms[] = $scopeName;
            }
        }
        
        // Add unique terms
        foreach (array_unique($terms) as $term) {
            $vocabulary->addBusinessTerm($term);
        }
    }
    
    private function inferTargetModel(string $relationshipName, ProjectMetadata $metadata): ?string
    {
        // Simple inference: relationship name often matches model name
        $possibleModel = Str::studly($relationshipName);
        $possibleModelPlural = Str::studly(Str::singular($relationshipName));
        
        foreach ($metadata->getModels() as $modelClass => $data) {
            $className = class_basename($modelClass);
            
            if ($className === $possibleModel || $className === $possibleModelPlural) {
                return $modelClass;
            }
        }
        
        return null;
    }
}

