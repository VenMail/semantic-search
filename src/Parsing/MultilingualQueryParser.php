<?php

namespace Venmail\SemanticSearch\Parsing;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Venmail\SemanticSearch\Core\LocaleManager;
use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\Action;
use Venmail\SemanticSearch\Data\Aggregation;
use Venmail\SemanticSearch\Data\Entity;
use Venmail\SemanticSearch\Data\Filter;
use Venmail\SemanticSearch\Data\ParsedQuery;
use Venmail\SemanticSearch\Data\Relationship;

class MultilingualQueryParser
{
    private LocaleManager $localeManager;
    private Vocabulary $vocabulary;
    private array $comparators;
    
    public function __construct(LocaleManager $localeManager, Vocabulary $vocabulary)
    {
        $this->localeManager = $localeManager;
        $this->vocabulary = $vocabulary;
        $this->loadComparators();
    }

    private function sanitizeSenderValue(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = preg_replace('/^from\s+/i', '', $value);
        $value = preg_replace('/\s+emails?$/i', '', $value);

        return trim($value);
    }

    private function inferSenderFieldCandidates(string $value): array
    {
        $candidates = ['sender_email', 'from_email', 'email', 'from'];

        if (!$this->looksLikeEmail($value)) {
            array_unshift($candidates, 'sender_name');
            $candidates[] = 'display_name';
        }

        return array_values(array_unique($candidates));
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    private function isComparatorToken(string $token): bool
    {
        $token = strtolower(trim($token));

        if (in_array($token, $this->comparators, true)) {
            return true;
        }

        foreach ($this->localeManager->getComparatorMapping($this->localeManager->getCurrentLocale()) as $variants) {
            if (in_array($token, $variants, true)) {
                return true;
            }
        }

        return false;
    }
    
    public function parse(string $query, ?string $locale = null): ParsedQuery
    {
        $locale = $locale ?: $this->localeManager->getCurrentLocale();
        
        if (!$this->localeManager->isSupported($locale)) {
            throw new \InvalidArgumentException("Locale '{$locale}' is not supported");
        }
        
        $tokens = $this->tokenize($query, $locale);
        $entities = $this->extractEntities($tokens, $locale);
        $actions = $this->extractActions($tokens, $locale);
        $filters = $this->extractFilters($tokens, $locale, $query);
        $relationships = $this->extractRelationships($tokens, $locale);
        $aggregations = $this->extractAggregations($tokens, $locale);
        $booleanOperator = $this->extractBooleanOperator($query, $locale);
        
        return new ParsedQuery(
            original: $query,
            tokens: $tokens,
            entities: $entities,
            actions: $actions,
            filters: $filters,
            relationships: $relationships,
            aggregations: $aggregations,
            booleanOperator: $booleanOperator,
            locale: $locale
        );
    }
    
    private function tokenize(string $query, string $locale): array
    {
        // Apply locale-specific normalization
        $normalized = $this->localeManager->normalizeToken($query, $locale);
        
        // Remove stop words
        $stopWords = $this->localeManager->getStopWords($locale);
        foreach ($stopWords as $stopWord) {
            $normalized = preg_replace("/\b{$stopWord}\b/u", '', $normalized);
        }
        
        // Preserve quoted phrases
        preg_match_all('/"([^"]+)"|[\p{L}\p{N}]+/u', $normalized, $matches);
        
        $tokens = array_map(function ($match) use ($locale) {
            return trim($this->localeManager->normalizeToken($match, $locale), '"');
        }, array_filter($matches[0]));
        
        return array_values(array_filter($tokens)); // Remove empty tokens
    }
    
    private function extractEntities(array $tokens, string $locale): array
    {
        $entities = [];
        
        foreach ($tokens as $token) {
            if ($this->vocabulary->isModel($token)) {
                $mapping = $this->vocabulary->getModelMapping($token);
                $entities[] = new Entity(
                    type: 'model',
                    value: $token,
                    mapping: $mapping['class'] ?? null,
                    confidence: 1.0,
                    locale: $locale
                );
            }
        }
        
        return $entities;
    }
    
    private function extractActions(array $tokens, string $locale): array
    {
        $actions = [];
        $actionWords = $this->localeManager->getActionWords($locale);
        
        foreach ($tokens as $token) {
            if (in_array($token, $actionWords, true) || $this->vocabulary->isAction($token)) {
                $mapping = $this->vocabulary->getActionMapping($token);
                $actions[] = new Action(
                    type: 'action',
                    value: $token,
                    mapping: $mapping['action'] ?? null,
                    confidence: 1.0,
                    locale: $locale
                );
            }
        }
        
        return $actions;
    }
    
    private function extractFilters(array $tokens, string $locale, string $originalQuery): array
    {
        $filters = [];

        $filters = array_merge($filters, $this->extractDateFilters($originalQuery, $locale));
        $filters = array_merge($filters, $this->extractNumericFilters($tokens, $locale));
        $filters = array_merge($filters, $this->extractTextFilters($tokens, $locale));
        $filters = array_merge($filters, $this->extractSenderFilters($originalQuery, $locale));
        $filters = array_merge($filters, $this->extractKeywordFilters($tokens, $locale, $originalQuery));

        return $filters;
    }

    private function extractKeywordFilters(array $tokens, string $locale, string $originalQuery): array
    {
        $filters = [];
        $phrases = [];

        if (preg_match_all('/"([^"]+)"/u', $originalQuery, $matches)) {
            foreach ($matches[1] as $match) {
                $phrases[] = trim($match);
            }
        }

        if (preg_match_all("/'([^']+)'/u", $originalQuery, $matches)) {
            foreach ($matches[1] as $match) {
                $phrases[] = trim($match);
            }
        }

        if (empty($phrases)) {
            $stopWords = $this->localeManager->getStopWords($locale);

            foreach ($tokens as $token) {
                $token = trim($token);
                $tokenLower = strtolower($token);

                if ($token === '' || is_numeric($token)) {
                    continue;
                }

                if (in_array($tokenLower, $stopWords, true)) {
                    continue;
                }

                if ($this->vocabulary->isModel($tokenLower) ||
                    $this->vocabulary->isField($tokenLower) ||
                    $this->vocabulary->isAction($tokenLower) ||
                    $this->isComparatorToken($tokenLower)) {
                    continue;
                }

                $phrases[] = $token;
            }
        }

        $phrases = array_slice(array_unique(array_filter($phrases)), 0, 5);

        if (empty($phrases)) {
            return $filters;
        }

        $searchableFields = ['subject', 'plain_body'];

        foreach ($phrases as $phrase) {
            foreach ($searchableFields as $field) {
                $fieldMapping = $this->vocabulary->getFieldMapping($field);
                $filters[] = new Filter(
                    field: $field,
                    operator: 'LIKE',
                    value: '%' . $phrase . '%',
                    mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                    confidence: 0.7,
                    locale: $locale
                );
            }
        }

        return $filters;
    }

    private function extractSenderFilters(string $originalQuery, string $locale): array
    {
        $filters = [];
        $pattern = '/\b(?:from|sender|by)\s+(?:"([^"]+)"|\'([^\']+)\'|([^\s,]+))/i';

        if (preg_match_all($pattern, $originalQuery, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = $match[1] ?: ($match[2] ?: ($match[3] ?? ''));
                $value = $this->sanitizeSenderValue($value);

                if ($value === '') {
                    continue;
                }

                foreach ($this->inferSenderFieldCandidates($value) as $field) {
                    $fieldMapping = $this->vocabulary->getFieldMapping($field);
                    $filters[] = new Filter(
                        field: $field,
                        operator: $this->looksLikeEmail($value) ? '=' : 'LIKE',
                        value: $this->looksLikeEmail($value) ? $value : '%' . $value . '%',
                        mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                        confidence: 0.85,
                        locale: $locale
                    );
                }
            }
        }

        return $filters;
    }
    
    private function extractNumericFilters(array $tokens, string $locale): array
    {
        $filters = [];
        $comparatorMapping = $this->localeManager->getComparatorMapping($locale);
        
        // Look for comparator patterns
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            
            // Check if token is a comparator (symbolic or textual)
            $operator = $this->identifyComparator($token, $comparatorMapping);
            
            if ($operator) {
                $field = $tokens[$i - 1] ?? null;
                $value = $tokens[$i + 1] ?? null;
                
                if ($field && $value && is_numeric($value)) {
                    $fieldMapping = null;
                    if ($this->vocabulary->isField($field)) {
                        $fieldMapping = $this->vocabulary->getFieldMapping($field);
                    }
                    
                    $filters[] = new Filter(
                        field: $field,
                        operator: $operator,
                        value: $this->normalizeValue($value),
                        mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                        confidence: 0.8,
                        locale: $locale
                    );
                }
            }
            
            // Handle multi-word comparators (e.g., "mayor que", "plus que")
            if ($i < count($tokens) - 2) {
                $phrase = $tokens[$i] . ' ' . $tokens[$i + 1];
                $operator = $this->identifyComparator($phrase, $comparatorMapping);
                
                if ($operator) {
                    $field = $tokens[$i - 1] ?? null;
                    $value = $tokens[$i + 2] ?? null;
                    
                    if ($field && $value && is_numeric($value)) {
                        $fieldMapping = null;
                        if ($this->vocabulary->isField($field)) {
                            $fieldMapping = $this->vocabulary->getFieldMapping($field);
                        }
                        
                        $filters[] = new Filter(
                            field: $field,
                            operator: $operator,
                            value: $this->normalizeValue($value),
                            mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                            confidence: 0.8,
                            locale: $locale
                        );
                    }
                }
            }
        }
        
        return $filters;
    }
    
    private function extractTextFilters(array $tokens, string $locale): array
    {
        $filters = [];
        
        // Handle quantifiers like "all", "todos", "tous"
        $quantifiers = $this->localeManager->getQuantifierPatterns($locale);
        
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            
            if (in_array($token, $quantifiers, true)) {
                $field = $tokens[$i + 1] ?? null;
                
                if ($field && $this->vocabulary->isField($field)) {
                    $fieldMapping = $this->vocabulary->getFieldMapping($field);
                    
                    // Convert quantifier to appropriate filter
                    $operator = $this->quantifierToOperator($token, $locale);
                    
                    $filters[] = new Filter(
                        field: $field,
                        operator: $operator,
                        value: $this->quantifierToValue($token, $locale),
                        mapping: $fieldMapping ? "{$fieldMapping['model']}.{$fieldMapping['field']}" : null,
                        confidence: 0.7,
                        locale: $locale
                    );
                }
            }
        }
        
        return $filters;
    }
    
    private function extractDateFilters(string $query, string $locale): array
    {
        $filters = [];

        $dateRange = MultilingualDatePhraseParser::parse($query, $locale);

        if (!$dateRange) {
            $dateRange = $this->parseRelativeDatePhrase($query);
        }

        if ($dateRange && isset($dateRange['from'], $dateRange['to'])) {
            $dateField = $this->resolveDateField($query, $locale);

            if ($dateRange['from'] === $dateRange['to']) {
                $filters[] = new Filter(
                    field: $dateField,
                    operator: '=',
                    value: $dateRange['from'],
                    mapping: null,
                    confidence: 0.95,
                    locale: $locale
                );
            } else {
                $filters[] = new Filter(
                    field: $dateField,
                    operator: '>=',
                    value: $dateRange['from'],
                    mapping: null,
                    confidence: 0.95,
                    locale: $locale
                );

                $filters[] = new Filter(
                    field: $dateField,
                    operator: '<=',
                    value: $dateRange['to'],
                    mapping: null,
                    confidence: 0.95,
                    locale: $locale
                );
            }
        }

        return $filters;
    }
    
    private function parseRelativeDatePhrase(string $query): ?array
    {
        $pattern = '/\b(?:last|past)\s+(?<quantity>\d+|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)?\s*(?<unit>day|week|month|year)s?\b/i';
        if (!preg_match($pattern, $query, $matches)) {
            return null;
        }

        $quantityToken = strtolower($matches['quantity'] ?? '');
        $quantity = is_numeric($quantityToken)
            ? (int) $quantityToken
            : ($this->wordToNumber($quantityToken) ?? 1);

        $unit = strtolower($matches['unit'] ?? 'day');

        $end = Carbon::now()->endOfDay();
        $start = match ($unit) {
            'week' => $end->copy()->subWeeks($quantity)->startOfDay(),
            'month' => $end->copy()->subMonths($quantity)->startOfDay(),
            'year' => $end->copy()->subYears($quantity)->startOfDay(),
            default => $end->copy()->subDays($quantity)->startOfDay(),
        };

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }
    
    private function resolveDateField(string $query, string $locale): string
    {
        // Try to extract entity from query to resolve date field
        $tokens = $this->tokenize($query, $locale);
        $entities = $this->extractEntities($tokens, $locale);
        
        if (!empty($entities)) {
            $entity = $entities[0];
            $modelClass = $entity->getMapping();
            
            if ($modelClass && class_exists($modelClass)) {
                try {
                    $schemaAnalyzer = app(\Venmail\SemanticSearch\Core\SchemaAnalyzer::class);
                    $schema = $schemaAnalyzer->getModelSchema($modelClass);
                    
                    // Prefer created_at, updated_at, or first date column
                    foreach (['created_at', 'updated_at', 'date', 'date_at'] as $field) {
                        if (isset($schema['columns'][$field])) {
                            $type = $schema['columns'][$field]['type'] ?? '';
                            if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                                return $field;
                            }
                        }
                    }
                    
                    // Find any date column
                    foreach ($schema['columns'] ?? [] as $col => $info) {
                        $type = $info['type'] ?? '';
                        if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
                            return $col;
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall through to default
                }
            }
        }
        
        // Safe default - most Laravel models have created_at
        return 'created_at';
    }

    private function wordToNumber(string $word): ?int
    {
        $mapping = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
            'eleven' => 11,
            'twelve' => 12,
        ];

        return $mapping[$word] ?? null;
    }
    
    private function extractRelationships(array $tokens, string $locale): array
    {
        $relationships = [];
        $relationshipWords = $this->localeManager->getRelationshipWords($locale);
        
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $current = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            
            // Pattern: "entity with entity" (locale-aware)
            if ($next && in_array($next, $relationshipWords, true)) {
                $target = $tokens[$i + 2] ?? null;
                
                if ($this->vocabulary->isModel($current) && 
                    $target && $this->vocabulary->isModel($target)) {
                    $mapping = $this->vocabulary->getRelationshipMapping($current, $target);
                    
                    $relationships[] = new Relationship(
                        from: $current,
                        to: $target,
                        mapping: $mapping ? $mapping['method'] : null,
                        type: $mapping['type'] ?? null,
                        confidence: 0.8,
                        locale: $locale
                    );
                }
            }
            
            // Pattern: "entity entity" (compound like "customer orders")
            if ($this->vocabulary->isModel($current) && 
                $next && $this->vocabulary->isModel($next)) {
                $mapping = $this->vocabulary->getRelationshipMapping($current, $next);
                
                if ($mapping) {
                    $relationships[] = new Relationship(
                        from: $current,
                        to: $next,
                        mapping: $mapping['method'],
                        type: $mapping['type'],
                        confidence: 0.7,
                        locale: $locale
                    );
                }
            }
        }
        
        return $relationships;
    }
    
    private function identifyComparator(string $token, array $comparatorMapping): ?string
    {
        $token = strtolower(trim($token));
        
        // Check symbolic comparators
        if (in_array($token, ['>', '<', '>=', '<=', '=', '!='], true)) {
            return $token;
        }
        
        // Check textual comparators
        foreach ($comparatorMapping as $operator => $variants) {
            if (in_array($token, $variants, true)) {
                return $operator;
            }
        }
        
        return null;
    }
    
    private function quantifierToOperator(string $quantifier, string $locale): string
    {
        $allQuantifiers = ['all', 'todos', 'tous', 'alle', 'todos'];
        if (in_array($quantifier, $allQuantifiers, true)) {
            return '!=';
        }
        
        return '=';
    }
    
    private function quantifierToValue(string $quantifier, string $locale): mixed
    {
        $allQuantifiers = ['all', 'todos', 'tous', 'alle', 'todos'];
        if (in_array($quantifier, $allQuantifiers, true)) {
            return ''; // Will be converted to NOT NULL in query builder
        }
        
        return $quantifier;
    }
    
    private function normalizeValue(mixed $value): mixed
    {
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $value)) {
            return $value;
        }
        
        return $value;
    }
    
    private function loadComparators(): void
    {
        $this->comparators = [
            '>', '<', '>=', '<=', '=', '!=',
            'older', 'younger', 'over', 'under', 'above', 'below'
        ];
    }
    
    private function extractAggregations(array $tokens, string $locale): array
    {
        $aggregations = [];
        $query = implode(' ', $tokens);
        
        // Pattern: "count users", "sum of transactions", "average age"
        $aggregationPatterns = [
            'count' => ['count', 'number of', 'total number', 'how many'],
            'sum' => ['sum', 'total', 'add up', 'sum of'],
            'avg' => ['average', 'avg', 'mean', 'average of'],
            'min' => ['minimum', 'min', 'lowest', 'smallest'],
            'max' => ['maximum', 'max', 'highest', 'largest'],
        ];
        
        foreach ($aggregationPatterns as $type => $patterns) {
            foreach ($patterns as $pattern) {
                $regex = '/\b' . preg_quote($pattern, '/') . '\s+(?:of\s+)?(\w+)/i';
                if (preg_match($regex, $query, $matches)) {
                    $field = $matches[1] ?? null;
                    
                    // Check if it's a model name (for count)
                    if ($type === 'count' && $this->vocabulary->isModel($field)) {
                        $aggregations[] = new Aggregation(
                            type: 'count',
                            field: null,
                            alias: 'total_count'
                        );
                    } elseif ($this->vocabulary->isField($field)) {
                        $fieldMapping = $this->vocabulary->getFieldMapping($field);
                        $aggregations[] = new Aggregation(
                            type: $type,
                            field: $field,
                            alias: $type . '_' . $field
                        );
                    }
                }
            }
        }
        
        // Pattern: "group by" or "by category"
        if (preg_match('/\b(?:group\s+by|by)\s+(\w+)/i', $query, $matches)) {
            $groupBy = $matches[1];
            foreach ($aggregations as $agg) {
                $agg->setGroupBy($groupBy);
            }
        }
        
        return $aggregations;
    }
    
    private function extractBooleanOperator(string $query, string $locale): ?string
    {
        $queryLower = strtolower($query);
        
        // Check for OR patterns
        if (preg_match('/\b(or|either|any of)\b/i', $queryLower)) {
            return 'OR';
        }
        
        // Check for AND patterns (explicit)
        if (preg_match('/\b(and|both|all of)\b/i', $queryLower)) {
            return 'AND';
        }
        
        // Check for NOT patterns
        if (preg_match('/\b(not|excluding|except|without)\b/i', $queryLower)) {
            // This would need special handling in query builder
            return 'AND'; // Default, but filters with NOT will be marked separately
        }
        
        // Default to AND
        return 'AND';
    }
}
