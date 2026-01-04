<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Venmail\SemanticSearch\Core\Vocabulary;

class SpellingCorrector
{
    private Vocabulary $vocabulary;
    private int $maxDistance;
    private float $minConfidence;
    private array $vocabularyCache = [];
    
    public function __construct(Vocabulary $vocabulary, int $maxDistance = 2, float $minConfidence = 0.6)
    {
        $this->vocabulary = $vocabulary;
        $this->maxDistance = $maxDistance;
        $this->minConfidence = $minConfidence;
    }
    
    public function correct(string $query): string
    {
        $tokens = $this->tokenize($query);
        $corrected = [];
        
        foreach ($tokens as $token) {
            if ($this->isKnownTerm($token)) {
                $corrected[] = $token;
            } else {
                $suggestion = $this->findBestMatch($token);
                $corrected[] = $suggestion ?: $token;
            }
        }
        
        return implode(' ', $corrected);
    }
    
    private function tokenize(string $query): array
    {
        return preg_split('/\s+/', strtolower(trim($query)));
    }
    
    private function isKnownTerm(string $token): bool
    {
        if (empty($this->vocabularyCache)) {
            $this->buildVocabularyCache();
        }
        
        return isset($this->vocabularyCache[strtolower($token)]);
    }
    
    private function findBestMatch(string $token): ?string
    {
        if (empty($this->vocabularyCache)) {
            $this->buildVocabularyCache();
        }
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($this->vocabularyCache as $term => $value) {
            $distance = levenshtein($token, $term);
            
            if ($distance <= $this->maxDistance) {
                $maxLen = max(strlen($token), strlen($term));
                $score = 1 - ($distance / $maxLen);
                
                if ($score > $bestScore && $score >= $this->minConfidence) {
                    $bestScore = $score;
                    $bestMatch = $term;
                }
            }
        }
        
        return $bestMatch;
    }
    
    private function buildVocabularyCache(): void
    {
        // Build from vocabulary
        $this->vocabularyCache = array_flip($this->vocabulary->getAllModelNames());
        
        foreach ($this->vocabulary->getAllFieldNames() as $field) {
            $this->vocabularyCache[strtolower($field)] = true;
        }
        
        foreach ($this->vocabulary->getAllActionWords() as $action) {
            $this->vocabularyCache[strtolower($action)] = true;
        }
    }
}

