<?php

namespace Venmail\SemanticSearch\Core\Intelligence;

use Venmail\SemanticSearch\Core\Config\SemanticFieldPatterns;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Action;

class ActionDetector
{
    private array $actionWords;
    
    public function __construct()
    {
        $this->actionWords = SemanticFieldPatterns::getActionWords();
    }
    
    /**
     * Detect and add missing action words to queries
     * e.g., "mails 3 days old" -> add "list" action
     */
    public function detectAndAddMissingAction(ParsedQuery $query): ParsedQuery
    {
        // If query already has an action, don't modify
        if (!empty($query->getActions())) {
            return $query;
        }
        
        $original = strtolower($query->getOriginal());
        
        // Pattern 1: "[model] [time/age]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+(?:\d+\s+(?:days?|weeks?|months?|years?|hours?|minutes?)\s+)?old\b/', $original, $matches)) {
            return $this->addAction($query, 'list');
        }
        
        // Pattern 2: "[model] [number]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+\d+\b/', $original)) {
            return $this->addAction($query, 'list');
        }
        
        // Pattern 3: "[model] [status]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+(?:active|inactive|pending|completed|cancelled|archived|deleted)\b/', $original)) {
            return $this->addAction($query, 'list');
        }
        
        // Pattern 4: "[model] [date/time]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+(?:today|yesterday|tomorrow|last|next|this|recent|latest|oldest)\b/', $original)) {
            return $this->addAction($query, 'list');
        }
        
        // Pattern 5: "[model] [attribute]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+(?:with|without|having|containing|including|excluding)\b/', $original)) {
            return $this->addAction($query, 'list');
        }
        
        // Pattern 6: Single model name -> default to "list" action
        if (preg_match('/^(\w+)$/', $original, $matches)) {
            $model = $matches[1];
            // Check if it's likely a model (not a common word)
            if (!$this->isCommonWord($model) && strlen($model) > 2) {
                return $this->addAction($query, 'list');
            }
        }
        
        // Pattern 7: "[model] [relationship] [target]" -> default to "list" action
        if (preg_match('/\b(\w+)\s+(from|to|by|with|about|in|for|on|at)\s+(\w+)\b/', $original)) {
            return $this->addAction($query, 'list');
        }
        
        return $query;
    }
    
    private function addAction(ParsedQuery $query, string $action): ParsedQuery
    {
        $newQuery = clone $query;
        
        // Add the action with high confidence
        $newQuery->actions[] = new Action($action, 0.9);
        
        return $newQuery;
    }
    
    private function isCommonWord(string $word): bool
    {
        $commonWords = array_merge(
            SemanticFieldPatterns::getStopWords(),
            ['help', 'search', 'find', 'show', 'get', 'all', 'any', 'some', 'more', 'less']
        );
        
        return in_array($word, $commonWords);
    }
    
    /**
     * Infer the most likely action based on query context
     */
    public function inferAction(ParsedQuery $query): string
    {
        $original = strtolower($query->getOriginal());
        
        // Check for explicit action words
        foreach ($this->actionWords as $action) {
            if (str_contains($original, $action)) {
                return $action;
            }
        }
        
        // Infer based on patterns
        if (preg_match('/\b(how|what|when|where|why|which)\b/', $original)) {
            return 'show'; // Information seeking
        }
        
        if (preg_match('/\b(new|create|add|make)\b/', $original)) {
            return 'create';
        }
        
        if (preg_match('/\b(change|update|edit|modify)\b/', $original)) {
            return 'update';
        }
        
        if (preg_match('/\b(delete|remove|destroy)\b/', $original)) {
            return 'delete';
        }
        
        // Default to list for most cases
        return 'list';
    }
    
    /**
     * Get action confidence based on query patterns
     */
    public function getActionConfidence(ParsedQuery $query, string $action): float
    {
        $original = strtolower($query->getOriginal());
        
        // High confidence for explicit action words
        if (str_contains($original, $action)) {
            return 0.9;
        }
        
        // Medium confidence for inferred actions
        switch ($action) {
            case 'list':
                if (preg_match('/\b(\w+)\s+(?:\d+\s+(?:days?|weeks?|months?|years?)\s+)?old\b/', $original)) {
                    return 0.8;
                }
                if (preg_match('/^(\w+)$/', $original)) {
                    return 0.7;
                }
                break;
                
            case 'show':
                if (preg_match('/\b(how|what|when|where|why|which)\b/', $original)) {
                    return 0.8;
                }
                break;
                
            case 'create':
                if (preg_match('/\b(new|create|add|make)\b/', $original)) {
                    return 0.8;
                }
                break;
                
            case 'update':
                if (preg_match('/\b(change|update|edit|modify)\b/', $original)) {
                    return 0.8;
                }
                break;
                
            case 'delete':
                if (preg_match('/\b(delete|remove|destroy)\b/', $original)) {
                    return 0.8;
                }
                break;
        }
        
        // Low confidence default
        return 0.5;
    }
}
