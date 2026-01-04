<?php

namespace Venmail\SemanticSearch\Disambiguation;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Disambiguation\SpellingCorrector;
use Venmail\SemanticSearch\Disambiguation\EntityResolver;
use Venmail\SemanticSearch\Disambiguation\ContextAnalyzer;
use Venmail\SemanticSearch\Disambiguation\SlangMemoryService;

class DisambiguationPipeline
{
    private SpellingCorrector $spellingCorrector;
    private EntityResolver $entityResolver;
    private ContextAnalyzer $contextAnalyzer;
    private SlangMemoryService $slangMemory;
    private Vocabulary $vocabulary;
    private float $confidenceThreshold;
    
    public function __construct(
        SpellingCorrector $spellingCorrector,
        EntityResolver $entityResolver,
        ContextAnalyzer $contextAnalyzer,
        SlangMemoryService $slangMemory,
        Vocabulary $vocabulary
    ) {
        $this->spellingCorrector = $spellingCorrector;
        $this->entityResolver = $entityResolver;
        $this->contextAnalyzer = $contextAnalyzer;
        $this->slangMemory = $slangMemory;
        $this->vocabulary = $vocabulary;
        $this->confidenceThreshold = config('semantic-search.disambiguation.confidence_threshold', 0.6);
    }
    
    public function process(string $query): DisambiguationResult
    {
        $startTime = microtime(true);
        
        // Layer 0: Apply slang/custom mappings
        $query = $this->slangMemory->applyMappings($query);
        
        // Layer 1: Basic spelling correction
        $corrected = $this->spellingCorrector->correct($query);
        $spellingConfidence = $this->calculateSpellingConfidence($query, $corrected);
        
        // Layer 2: Entity disambiguation
        $entityConfidence = $this->analyzeEntityConfidence($corrected);
        
        // Layer 3: Context analysis
        $contextResult = $this->contextAnalyzer->analyze($corrected);
        $contextConfidence = $contextResult['confidence'] ?? 0.5;
        
        // Calculate overall confidence
        $overallConfidence = ($spellingConfidence * 0.3) + ($entityConfidence * 0.4) + ($contextConfidence * 0.3);
        
        // Determine if parseable
        $parseable = $overallConfidence >= $this->confidenceThreshold;
        
        // Generate suggestions if low confidence
        $suggestions = [];
        if (!$parseable || $overallConfidence < 0.7) {
            $suggestions = $this->generateSuggestions($corrected, $contextResult);
        }
        
        $processingTime = microtime(true) - $startTime;
        
        return new DisambiguationResult(
            originalQuery: $query,
            correctedQuery: $corrected,
            confidence: $overallConfidence,
            parseable: $parseable,
            failureReason: $parseable ? null : 'Low confidence score',
            suggestions: $suggestions,
            source: 'disambiguation_pipeline',
            processingTime: $processingTime
        );
    }
    
    public function enhanceParsedQuery(ParsedQuery $parsedQuery): ParsedQuery
    {
        // Enhance entities with disambiguation
        $entities = $parsedQuery->getEntities();
        
        foreach ($entities as $entity) {
            if ($entity->getConfidence() < 0.7) {
                // Try to resolve ambiguous entity
                $resolved = $this->entityResolver->resolve($entity->getValue());
                if ($resolved) {
                    $entity->mapping = $resolved;
                    $entity->confidence = 0.8;
                }
            }
        }
        
        return $parsedQuery;
    }
    
    private function calculateSpellingConfidence(string $original, string $corrected): float
    {
        if ($original === $corrected) {
            return 1.0;
        }
        
        // Calculate how much was corrected
        $originalTokens = explode(' ', strtolower($original));
        $correctedTokens = explode(' ', strtolower($corrected));
        
        $changes = 0;
        $maxLen = max(count($originalTokens), count($correctedTokens));
        
        for ($i = 0; $i < min(count($originalTokens), count($correctedTokens)); $i++) {
            if ($originalTokens[$i] !== $correctedTokens[$i]) {
                $changes++;
            }
        }
        
        if ($maxLen === 0) {
            return 1.0;
        }
        
        return 1.0 - ($changes / $maxLen);
    }
    
    private function analyzeEntityConfidence(string $query): float
    {
        $tokens = preg_split('/\s+/', strtolower($query));
        $foundEntities = 0;
        $totalTokens = count($tokens);
        
        foreach ($tokens as $token) {
            if ($this->vocabulary->isModel($token) || 
                $this->vocabulary->isField($token) ||
                $this->vocabulary->isAction($token)) {
                $foundEntities++;
            }
        }
        
        if ($totalTokens === 0) {
            return 0.0;
        }
        
        // Higher confidence if we found recognizable entities
        return min(1.0, ($foundEntities / $totalTokens) * 1.5);
    }
    
    private function generateSuggestions(string $query, array $contextResult): array
    {
        $suggestions = [];
        
        // Suggest common model names if no entities found
        if (empty($contextResult['entities'] ?? [])) {
            $commonModels = array_slice($this->vocabulary->getAllModelNames(), 0, 3);
            foreach ($commonModels as $model) {
                $suggestions[] = [
                    'label' => "Show {$model}",
                    'prompt' => "show {$model}",
                    'rationale' => 'No entities found in query'
                ];
            }
        }
        
        // Suggest adding date filters if missing
        if (empty($contextResult['date'] ?? [])) {
            $suggestions[] = [
                'label' => 'Add date range (last 30 days)',
                'prompt' => trim($query) . ' in the last 30 days',
                'rationale' => 'Date filter can help narrow results'
            ];
        }
        
        // Suggest common actions
        $commonActions = ['show', 'list', 'find', 'count'];
        foreach ($commonActions as $action) {
            if (stripos($query, $action) === false) {
                $suggestions[] = [
                    'label' => "Use '{$action}' action",
                    'prompt' => "{$action} " . trim($query),
                    'rationale' => 'Adding an action verb can improve query clarity'
                ];
                break; // Only suggest one action
            }
        }
        
        return array_slice($suggestions, 0, 5); // Limit to 5 suggestions
    }
}

