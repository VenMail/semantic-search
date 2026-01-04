<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Venmail\SemanticSearch\Core\Vocabulary;

class ContextAnalyzer
{
    private Vocabulary $vocabulary;
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->vocabulary = $vocabulary;
    }
    
    public function analyze(string $query): array
    {
        $confidence = 1.0;
        $entities = [];
        $date = $this->extractDateRange($query);
        $filters = $this->extractFilterHints($query);
        
        // Extract entities
        $tokens = preg_split('/\s+/', strtolower($query));
        foreach ($tokens as $token) {
            if ($this->vocabulary->isModel($token)) {
                $entities[] = $token;
            }
        }
        
        // Lower confidence if no entities found
        if (empty($entities)) {
            $confidence *= 0.6;
        }
        
        // Lower confidence if query is too short
        if (count($tokens) < 2) {
            $confidence *= 0.7;
        }
        
        // Higher confidence if date range is present
        if (!empty($date)) {
            $confidence *= 1.1;
            $confidence = min(1.0, $confidence);
        }
        
        return [
            'confidence' => $confidence,
            'entities' => $entities,
            'date' => $date,
            'filters' => $filters,
        ];
    }
    
    private function extractDateRange(string $query): array
    {
        // Use the sophisticated DatePhraseParser
        $parsed = \Venmail\SemanticSearch\Parsing\DatePhraseParser::parse($query);
        
        if ($parsed) {
            return $parsed;
        }
        
        return [];
    }
    
    private function extractFilterHints(string $query): array
    {
        $filters = [];
        
        // Pattern: amount/price/value ranges (generic numeric fields)
        if (preg_match('/\b(?:amount|total|value|price|cost)\s+(?:over|above|greater\s+than|>)\s+([\d,]+)/i', $query, $matches)) {
            $filters['amount'] = ['min' => (float)str_replace(',', '', $matches[1])];
        }
        
        if (preg_match('/\b(?:amount|total|value|price|cost)\s+(?:under|below|less\s+than|<)\s+([\d,]+)/i', $query, $matches)) {
            $filters['amount'] = ['max' => (float)str_replace(',', '', $matches[1])];
        }
        
        // Pattern: score/rating ranges (generic scoring fields)
        if (preg_match('/\b(?:score|rating|rank)\s+(?:over|above|greater\s+than|>)\s+([\d.]+)/i', $query, $matches)) {
            $filters['score'] = ['min' => (float)$matches[1]];
        }
        
        if (preg_match('/\b(?:score|rating|rank)\s+(?:under|below|less\s+than|<)\s+([\d.]+)/i', $query, $matches)) {
            $filters['score'] = ['max' => (float)$matches[1]];
        }
        
        return $filters;
    }
}
