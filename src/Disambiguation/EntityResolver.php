<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Venmail\SemanticSearch\Core\Vocabulary;

class EntityResolver
{
    private Vocabulary $vocabulary;
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }
    
    public function resolve(string $entityName): ?string
    {
        // Try to find model mapping
        $mapping = $this->vocabulary->getModelMapping($entityName);
        
        if ($mapping) {
            return $mapping['class'] ?? null;
        }
        
        // Try fuzzy matching
        $allModels = $this->vocabulary->getAllModelNames();
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($allModels as $modelName) {
            $similarity = $this->calculateSimilarity($entityName, $modelName);
            if ($similarity > $bestScore && $similarity > 0.7) {
                $bestScore = $similarity;
                $bestMatch = $modelName;
            }
        }
        
        if ($bestMatch) {
            $mapping = $this->vocabulary->getModelMapping($bestMatch);
            return $mapping['class'] ?? null;
        }
        
        return null;
    }
    
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $str1 = strtolower($str1);
        $str2 = strtolower($str2);
        
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        return 1 - ($distance / $maxLen);
    }
}



