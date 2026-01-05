<?php

namespace Venmail\SemanticSearch\Core\Validation;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;

class SQLStructureValidator
{
    private Vocabulary $vocabulary;
    private array $validOperators = ['=', '!=', '>', '<', '>=', '<=', 'like', 'ilike', 'in', 'not in'];
    private array $validLogicalOperators = ['and', 'or'];
    private array $sqlKeywords = ['select', 'from', 'where', 'order by', 'group by', 'having', 'limit', 'offset'];
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }
    
    public function validateAndFix(ParsedQuery $query): ParsedQuery
    {
        $fixedQuery = clone $query;
        
        // Validate and fix each component
        $this->validateModel($fixedQuery);
        $this->validateFields($fixedQuery);
        $this->validateConditions($fixedQuery);
        $this->validateRelationships($fixedQuery);
        
        return $fixedQuery;
    }
    
    private function validateModel(ParsedQuery $query): void
    {
        $model = $query->getModel();
        
        if (!$model || !$this->vocabulary->isModel($model)) {
            // Try to find the closest model match
            $closestModel = $this->findClosestModel($query->getOriginalQuery());
            if ($closestModel) {
                $query->setModel($closestModel);
            } else {
                // Default to first available model
                $models = $this->vocabulary->getAllModelNames();
                if (!empty($models)) {
                    $query->setModel($models[0]);
                }
            }
        }
    }
    
    private function validateFields(ParsedQuery $query): void
    {
        $model = $query->getModel();
        $fields = $query->getFields();
        
        if (!$model) return;
        
        $validFields = $this->vocabulary->getFieldsForModel($model);
        
        foreach ($fields as $field) {
            if (!in_array($field->getName(), $validFields)) {
                // Try to find closest field match
                $closestField = $this->findClosestField($field->getName(), $validFields);
                if ($closestField) {
                    $field->setName($closestField);
                } else {
                    // Remove invalid field
                    $query->removeField($field);
                }
            }
        }
        
        // If no valid fields, add common fields
        if (empty($query->getFields())) {
            $commonFields = array_slice($validFields, 0, 3);
            foreach ($commonFields as $fieldName) {
                $query->addField($fieldName);
            }
        }
    }
    
    private function validateConditions(ParsedQuery $query): void
    {
        $conditions = $query->getConditions();
        $model = $query->getModel();
        
        if (!$model) return;
        
        $validFields = $this->vocabulary->getFieldsForModel($model);
        
        foreach ($conditions as $condition) {
            // Validate field
            if (!in_array($condition->getField(), $validFields)) {
                $closestField = $this->findClosestField($condition->getField(), $validFields);
                if ($closestField) {
                    $condition->setField($closestField);
                } else {
                    $query->removeCondition($condition);
                    continue;
                }
            }
            
            // Validate operator
            if (!in_array(strtolower($condition->getOperator()), $this->validOperators)) {
                $condition->setOperator('LIKE'); // Default to LIKE
            }
            
            // Validate value
            $this->validateConditionValue($condition);
        }
    }
    
    private function validateRelationships(ParsedQuery $query): void
    {
        $relationships = $query->getRelationships();
        
        foreach ($relationships as $relationship) {
            $relatedModel = $relationship->getRelatedModel();
            
            if (!$this->vocabulary->isModel($relatedModel)) {
                // Try to find closest model
                $closestModel = $this->findClosestModel($relatedModel);
                if ($closestModel) {
                    $relationship->setRelatedModel($closestModel);
                } else {
                    $query->removeRelationship($relationship);
                }
            }
        }
    }
    
    private function validateConditionValue($condition): void
    {
        $value = $condition->getValue();
        $operator = strtolower($condition->getOperator());
        
        // Handle empty values
        if (empty($value) && $value !== '0') {
            $condition->setValue('%'); // Default to wildcard for LIKE
            $condition->setOperator('LIKE');
            return;
        }
        
        // Handle array values for IN/NOT IN
        if (in_array($operator, ['in', 'not in']) && !is_array($value)) {
            $condition->setValue([$value]);
        }
        
        // Handle LIKE values
        if ($operator === 'like' && !str_contains($value, '%')) {
            $condition->setValue('%' . $value . '%');
        }
    }
    
    private function findClosestModel(string $input): ?string
    {
        $models = $this->vocabulary->getAllModelNames();
        $input = strtolower($input);
        
        // Exact match
        if (in_array($input, $models)) {
            return $input;
        }
        
        // Find closest match using Levenshtein distance
        $closest = null;
        $minDistance = PHP_INT_MAX;
        
        foreach ($models as $model) {
            $distance = levenshtein($input, strtolower($model));
            if ($distance < $minDistance && $distance <= 2) {
                $minDistance = $distance;
                $closest = $model;
            }
        }
        
        return $closest;
    }
    
    private function findClosestField(string $input, array $validFields): ?string
    {
        $input = strtolower($input);
        
        // Exact match
        if (in_array($input, $validFields)) {
            return $input;
        }
        
        // Find closest match
        $closest = null;
        $minDistance = PHP_INT_MAX;
        
        foreach ($validFields as $field) {
            $distance = levenshtein($input, strtolower($field));
            if ($distance < $minDistance && $distance <= 2) {
                $minDistance = $distance;
                $closest = $field;
            }
        }
        
        return $closest;
    }
    
    public function buildSafeQuery(ParsedQuery $query): array
    {
        $validated = $this->validateAndFix($query);
        
        return [
            'model' => $validated->getModel(),
            'fields' => array_map(fn($f) => $f->getName(), $validated->getFields()),
            'conditions' => array_map(fn($c) => [
                'field' => $c->getField(),
                'operator' => $c->getOperator(),
                'value' => $c->getValue(),
                'logical' => $c->getLogicalOperator() ?? 'AND'
            ], $validated->getConditions()),
            'relationships' => array_map(fn($r) => [
                'type' => $r->getType(),
                'related_model' => $r->getRelatedModel(),
                'conditions' => $r->getConditions()
            ], $validated->getRelationships()),
            'order' => $validated->getOrderBy(),
            'limit' => $validated->getLimit(),
            'offset' => $validated->getOffset()
        ];
    }
}
