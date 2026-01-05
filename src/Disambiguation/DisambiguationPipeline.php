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
        
        // DEBUG: Force output for this specific query
        if (str_contains($corrected, 'proposal from ja')) {
            echo "DEBUG: overallConfidence = $overallConfidence\n";
            echo "DEBUG: parseable = " . ($overallConfidence >= 0.5 ? 'true' : 'false') . "\n";
        }
        
        // Determine if parseable
        $parseable = $overallConfidence >= 0.5; // Temporarily lowered threshold
        
        // Layer 4: LLM fallback if still unparseable
        if (!$parseable && config('semantic-search.llm.enabled', false)) {
            try {
                // Get metadata from SearchEngine or create new instance
                $metadata = null;
                try {
                    $searchEngine = app(\Venmail\SemanticSearch\Core\SearchEngine::class);
                    // Use reflection to access private metadata property
                    $reflection = new \ReflectionClass($searchEngine);
                    $metadataProperty = $reflection->getProperty('metadata');
                    $metadataProperty->setAccessible(true);
                    $metadata = $metadataProperty->getValue($searchEngine);
                } catch (\Throwable $e) {
                    // Fallback: try to get from analyzer
                    $analyzer = app(\Venmail\SemanticSearch\Core\ProjectAnalyzer::class);
                    $metadata = $analyzer->analyze();
                }
                
                if ($metadata) {
                    $llmAdapter = new \Venmail\SemanticSearch\Disambiguation\LLMFallbackAdapter($metadata);
                    $llmResult = $llmAdapter->process($corrected);
                    
                    if ($llmResult->isParseable()) {
                        return $llmResult;
                    }
                    
                    // If LLM also fails, merge suggestions
                    $suggestions = array_merge(
                        $this->generateSuggestions($corrected, $contextResult),
                        $llmResult->getSuggestions()
                    );
                }
            } catch (\Throwable $e) {
                // Log but don't fail - continue with static disambiguation
                \Illuminate\Support\Facades\Log::warning('LLM fallback error', [
                    'error' => $e->getMessage(),
                    'query' => $corrected
                ]);
            }
        }
        
        // Normalize suggestions collection
        if (!isset($suggestions)) {
            $suggestions = [];
        }
        
        // Generate suggestions if low confidence
        if (!$parseable || $overallConfidence < 0.7) {
            $suggestions = array_merge($suggestions, $this->generateSuggestions($corrected, $contextResult));
        }
        
        $processingTime = microtime(true) - $startTime;
        
        return new DisambiguationResult(
            originalQuery: $query,
            correctedQuery: $corrected,
            confidence: $overallConfidence,
            parseable: $parseable,
            failureReason: $parseable ? null : 'Low confidence score',
            suggestions: array_slice($this->deduplicateSuggestions($suggestions), 0, 5),
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
    
    private function deduplicateSuggestions(array $suggestions): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($suggestions as $suggestion) {
            $key = is_array($suggestion)
                ? $this->buildSuggestionKey($suggestion)
                : (is_scalar($suggestion) ? (string) $suggestion : md5(serialize($suggestion)));
            
            if (!isset($seen[$key])) {
                $unique[] = $suggestion;
                $seen[$key] = true;
            }
        }
        
        return $unique;
    }
    
    private function buildSuggestionKey(array $suggestion): string
    {
        ksort($suggestion);
        
        return md5(json_encode($suggestion));
    }
}


