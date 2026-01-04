<?php

namespace Venmail\SemanticSearch\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\ProjectMetadata;

class PolicyGate
{
    private array $rules = [];
    private int $maxRows = 10000;
    private array $restrictedModels = [];
    private array $restrictedFields = [];
    
    public function __construct()
    {
        $this->maxRows = config('semantic-search.security.max_rows', 10000);
        $this->restrictedModels = config('semantic-search.security.restricted_models', []);
        $this->restrictedFields = config('semantic-search.security.restricted_fields', []);
    }
    
    public function authorize(ParsedQuery $parsedQuery, ProjectMetadata $metadata, ?Authenticatable $user = null): void
    {
        // Check model access
        foreach ($parsedQuery->getEntities() as $entity) {
            $modelClass = $entity->getMapping();
            
            if ($modelClass && $this->isModelRestricted($modelClass)) {
                throw new AuthorizationException(
                    "Access denied: You do not have permission to query the '{$modelClass}' model."
                );
            }
        }
        
        // Check relationship traversal
        foreach ($parsedQuery->getRelationships() as $relationship) {
            if (!$this->canTraverseRelationship($relationship, $user)) {
                throw new AuthorizationException(
                    "Access denied: You do not have permission to traverse the relationship " .
                    "from '{$relationship->getFrom()}' to '{$relationship->getTo()}'."
                );
            }
        }
        
        // Check for restricted fields
        foreach ($parsedQuery->getFilters() as $filter) {
            $field = $filter->getField();
            if ($this->isFieldRestricted($field)) {
                throw new AuthorizationException(
                    "Access denied: You do not have permission to filter by the '{$field}' field."
                );
            }
        }
        
        // Check row limits
        $this->validateRowLimits($parsedQuery);
    }
    
    private function isModelRestricted(string $modelClass): bool
    {
        return in_array($modelClass, $this->restrictedModels, true);
    }
    
    private function canTraverseRelationship($relationship, ?Authenticatable $user): bool
    {
        // Default: allow all relationships
        // Can be extended with user-specific rules
        return true;
    }
    
    private function isFieldRestricted(string $field): bool
    {
        // Check for PII or sensitive fields
        $sensitivePatterns = ['password', 'secret', 'token', 'key', 'ssn', 'credit_card'];
        
        foreach ($sensitivePatterns as $pattern) {
            if (stripos($field, $pattern) !== false) {
                return in_array($field, $this->restrictedFields, true);
            }
        }
        
        return false;
    }
    
    private function validateRowLimits(ParsedQuery $parsedQuery): void
    {
        // This would check if query would return too many rows
        // For now, we rely on pagination/limit options
        // Could be enhanced with EXPLAIN query analysis
    }
    
    public function setMaxRows(int $maxRows): void
    {
        $this->maxRows = $maxRows;
    }
    
    public function addRestrictedModel(string $modelClass): void
    {
        $this->restrictedModels[] = $modelClass;
    }
    
    public function addRestrictedField(string $field): void
    {
        $this->restrictedFields[] = $field;
    }
}


