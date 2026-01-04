<?php

namespace Venmail\SemanticSearch\Parsing;

use Carbon\Carbon;
use Venmail\SemanticSearch\Core\LocaleManager;

class MultilingualDatePhraseParser
{
    private LocaleManager $localeManager;
    
    public function __construct(LocaleManager $localeManager)
    {
        $this->localeManager = $localeManager;
    }
    
    public static function parse(string $text, string $locale = 'en'): ?array
    {
        $instance = new self(app(LocaleManager::class));
        return $instance->parseDatePhrase($text, $locale);
    }
    
    public function parseDatePhrase(string $text, string $locale): ?array
    {
        $range = $this->parseRange($text, $locale);
        if ($range) {
            return $range;
        }

        $single = $this->parseSingle($text, $locale);
        if ($single) {
            return $single;
        }

        $relative = $this->parseRelative($text, $locale);
        if ($relative) {
            return $relative;
        }

        return null;
    }

    public function parseRange(string $text, string $locale): ?array
    {
        $normalized = $this->normalize($text, $locale);
        if ($normalized === '') {
            return null;
        }

        $patterns = $this->getRangePatterns($locale);
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $startToken = $this->extractDateToken($matches[1] ?? '');
                $endToken = $this->extractDateToken($matches[2] ?? '');
                
                if ($startToken && $endToken) {
                    $start = $this->parseDateToken($startToken);
                    $end = $this->parseDateToken($endToken);
                    
                    if ($start && $end) {
                        if ($end->lessThan($start)) {
                            [$start, $end] = [$end, $start];
                        }
                        return ['from' => $start->toDateString(), 'to' => $end->toDateString()];
                    }
                }
            }
        }

        return null;
    }

    public function parseSingle(string $text, string $locale): ?array
    {
        $normalized = $this->normalize($text, $locale);
        if ($normalized === '') {
            return null;
        }

        $patterns = $this->getSinglePatterns($locale);

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $token = $this->extractDateToken($matches[1] ?? '');
                if ($token) {
                    $parsed = $this->parseDateToken($token);
                    if ($parsed) {
                        $date = $parsed->toDateString();
                        return ['from' => $date, 'to' => $date];
                    }
                }
            }
        }

        return null;
    }

    public function parseRelative(string $text, string $locale): ?array
    {
        $normalized = $this->normalize($text, $locale);
        if ($normalized === '') {
            return null;
        }

        $datePatterns = $this->localeManager->getDatePatterns($locale);
        
        foreach ($datePatterns as $key => $pattern) {
            if (is_string($pattern) && str_contains($key, 'today')) {
                if (preg_match("/\b{$pattern}\b/i", $normalized)) {
                    return [
                        'from' => Carbon::today()->toDateString(),
                        'to' => Carbon::today()->toDateString()
                    ];
                }
            } elseif (is_string($pattern) && str_contains($key, 'yesterday')) {
                if (preg_match("/\b{$pattern}\b/i", $normalized)) {
                    return [
                        'from' => Carbon::yesterday()->toDateString(),
                        'to' => Carbon::yesterday()->toDateString()
                    ];
                }
            } elseif (is_string($pattern) && str_contains($key, 'week')) {
                if (preg_match("/\b{$pattern}\b/i", $normalized)) {
                    $isLast = str_contains($key, 'last');
                    $week = $isLast ? Carbon::now()->subWeek() : Carbon::now();
                    
                    return [
                        'from' => $week->startOfWeek()->toDateString(),
                        'to' => $week->endOfWeek()->toDateString()
                    ];
                }
            } elseif (is_string($pattern) && str_contains($key, 'month')) {
                if (preg_match("/\b{$pattern}\b/i", $normalized)) {
                    $isLast = str_contains($key, 'last');
                    $month = $isLast ? Carbon::now()->subMonth() : Carbon::now();
                    
                    return [
                        'from' => $month->startOfMonth()->toDateString(),
                        'to' => $month->endOfMonth()->toDateString()
                    ];
                }
            } elseif (is_string($pattern) && str_contains($key, 'year')) {
                if (preg_match("/\b{$pattern}\b/i", $normalized)) {
                    $isLast = str_contains($key, 'last');
                    $year = $isLast ? Carbon::now()->subYear() : Carbon::now();
                    
                    return [
                        'from' => $year->startOfYear()->toDateString(),
                        'to' => $year->endOfYear()->toDateString()
                    ];
                }
            } elseif (is_array($pattern) && isset($pattern['regex'])) {
                if (preg_match($pattern['regex'], $normalized, $matches)) {
                    $days = (int)($matches[1] ?? 0);
                    return [
                        'from' => Carbon::today()->subDays($days)->toDateString(),
                        'to' => Carbon::today()->toDateString()
                    ];
                }
            }
        }

        return null;
    }

    private function getRangePatterns(string $locale): array
    {
        $patterns = [
            'en' => [
                '/\bbetween\s+(.+?)\s+(?:and|to)\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
                '/\bfrom\s+(.+?)\s+(?:to|until|till|through|thru|-)\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
            ],
            'es' => [
                '/\bentre\s+(.+?)\s+(?:y|a)\s+(.+?)(?=$|\s+(?:para|con|donde|que|cual|quien|en|mostrar|listar)\b|[,.;])/i',
                '/\bdesde\s+(.+?)\s+(?:hasta|al)\s+(.+?)(?=$|\s+(?:para|con|donde|que|cual|quien|en|mostrar|listar)\b|[,.;])/i',
            ],
            'fr' => [
                '/\bentre\s+(.+?)\s+(?:et|à)\s+(.+?)(?=$|\s+(?:pour|avec|où|que|lequel|qui|dans|montrer|lister)\b|[,.;])/i',
                '/\bde\s+(.+?)\s+(?:à|jusqu\'à)\s+(.+?)(?=$|\s+(?:pour|avec|où|que|lequel|qui|dans|montrer|lister)\b|[,.;])/i',
            ],
            'de' => [
                '/\bzwischen\s+(.+?)\s+(?:und|bis)\s+(.+?)(?=$|\s+(?:für|mit|wo|dass|welcher|wer|in|zeigen|auflisten)\b|[,.;])/i',
                '/\bvon\s+(.+?)\s+(?:bis|zu)\s+(.+?)(?=$|\s+(?:für|mit|wo|dass|welcher|wer|in|zeigen|auflisten)\b|[,.;])/i',
            ],
            'pt' => [
                '/\bentre\s+(.+?)\s+(?:e|a)\s+(.+?)(?=$|\s+(?:para|com|onde|que|qual|quem|em|mostrar|listar)\b|[,.;])/i',
                '/\bde\s+(.+?)\s+(?:a|até)\s+(.+?)(?=$|\s+(?:para|com|onde|que|qual|quem|em|mostrar|listar)\b|[,.;])/i',
            ],
        ];

        return $patterns[$locale] ?? $patterns['en'];
    }

    private function getSinglePatterns(string $locale): array
    {
        $patterns = [
            'en' => [
                '/\bon\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
                '/\bdated\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
            ],
            'es' => [
                '/\ben\s+(.+?)(?=$|\s+(?:para|con|donde|que|cual|quien|en|mostrar|listar)\b|[,.;])/i',
                '/\bfecha\s+(.+?)(?=$|\s+(?:para|con|donde|que|cual|quien|en|mostrar|listar)\b|[,.;])/i',
            ],
            'fr' => [
                '/\ben\s+(.+?)(?=$|\s+(?:pour|avec|où|que|lequel|qui|dans|montrer|lister)\b|[,.;])/i',
                '/\bdate\s+(.+?)(?=$|\s+(?:pour|avec|où|que|lequel|qui|dans|montrer|lister)\b|[,.;])/i',
            ],
            'de' => [
                '/\bam\s+(.+?)(?=$|\s+(?:für|mit|wo|dass|welcher|wer|in|zeigen|auflisten)\b|[,.;])/i',
                '/\bdatum\s+(.+?)(?=$|\s+(?:für|mit|wo|dass|welcher|wer|in|zeigen|auflisten)\b|[,.;])/i',
            ],
            'pt' => [
                '/\bem\s+(.+?)(?=$|\s+(?:para|com|onde|que|qual|quem|em|mostrar|listar)\b|[,.;])/i',
                '/\bdata\s+(.+?)(?=$|\s+(?:para|com|onde|que|qual|quem|em|mostrar|listar)\b|[,.;])/i',
            ],
        ];

        return $patterns[$locale] ?? $patterns['en'];
    }

    private function normalize(string $text, string $locale): string
    {
        $stripped = $this->stripOrdinalSuffixes($text);
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped));
        return $this->localeManager->normalizeToken($stripped, $locale);
    }

    private function stripOrdinalSuffixes(string $text): string
    {
        return preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $text);
    }

    private function extractDateToken(string $fragment): ?string
    {
        $fragment = trim($fragment, " \t\n\r\0\x0B,.");
        if ($fragment === '') {
            return null;
        }

        $candidates = [
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/',
            '/\b\d{1,2}\s+[A-Za-z]+(?:\s+\d{2,4})?\b/',
            '/\b[A-Za-z]+\s+\d{1,2}(?:,\s*\d{2,4})?\b/',
        ];

        foreach ($candidates as $regex) {
            if (preg_match($regex, $fragment, $matches)) {
                return trim($matches[0]);
            }
        }

        return $fragment;
    }

    private function parseDateToken(string $token): ?Carbon
    {
        try {
            return Carbon::parse($token);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
