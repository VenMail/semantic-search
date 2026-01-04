<?php

namespace Venmail\SemanticSearch\Parsing;

use Carbon\Carbon;

class DatePhraseParser
{
    public static function parse(string $text): ?array
    {
        $range = self::parseRange($text);
        if ($range) {
            return $range;
        }

        $single = self::parseSingle($text);
        if ($single) {
            return $single;
        }

        // Try relative date patterns
        $relative = self::parseRelative($text);
        if ($relative) {
            return $relative;
        }

        return null;
    }

    public static function parseRange(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return null;
        }

        // Time window with relative day/date, e.g. "between 10am and 2pm yesterday", "from 10:00 to 14:00 on 2025-10-13"
        if (preg_match('/\b(between|from)\s+([0-9]{1,2}(?::[0-9]{2})?\s*(?:am|pm)?)\s*(?:and|to|\-)\s*([0-9]{1,2}(?::[0-9]{2})?\s*(?:am|pm)?)\s+(?:on\s+)?(yesterday|today|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4}|[A-Za-z]+\s+\d{1,2}(?:,\s*\d{2,4})?|\d{1,2}\s+[A-Za-z]+(?:\s+\d{2,4})?)\b/i', $normalized, $tm)) {
            $t1 = trim($tm[2]);
            $t2 = trim($tm[3]);
            $dayToken = trim($tm[4]);
            $dateStr = self::coerceDateFromPhrase($dayToken);
            if ($dateStr) {
                $timeFrom = self::coerceTimeToHms($t1);
                $timeTo = self::coerceTimeToHms($t2);
                if ($timeFrom && $timeTo) {
                    // Ensure ordering
                    if (strtotime($timeTo) < strtotime($timeFrom)) {
                        [$timeFrom, $timeTo] = [$timeTo, $timeFrom];
                    }
                    return [
                        'from' => $dateStr,
                        'to' => $dateStr,
                        'time' => ['from' => $timeFrom, 'to' => $timeTo]
                    ];
                }
            }
        }

        $patterns = [
            '/\bbetween\s+(.+?)\s+(?:and|to)\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
            '/\bfrom\s+(.+?)\s+(?:to|until|till|through|thru|-)\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
            '/\b(\d{1,2}\s+[A-Za-z]+(?:\s+\d{2,4})?)\s*(?:to|through|until|till|-)\s*(\d{1,2}\s+[A-Za-z]+(?:\s+\d{2,4})?)\b/i',
            '/\b([A-Za-z]+\s+\d{1,2}(?:,\s*\d{2,4})?)\s*(?:to|through|until|till|-)\s*([A-Za-z]+\s+\d{1,2}(?:,\s*\d{2,4})?)\b/i',
            '/\b(\d{4}-\d{2}-\d{2})\s*(?:to|through|until|till|-)\s*(\d{4}-\d{2}-\d{2})\b/',
            '/\b(\d{1,2}\/\d{1,2}\/\d{2,4})\s*(?:to|through|until|till|-)\s*(\d{1,2}\/\d{1,2}\/\d{2,4})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $startToken = self::extractDateToken($matches[1]);
                $endToken = self::extractDateToken($matches[2]);
                if ($startToken && $endToken) {
                    $start = self::parseDateToken($startToken);
                    $end = self::parseDateToken($endToken);
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

    public static function parseSingle(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            '/\bon\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
            '/\bdated\s+(.+?)(?=$|\s+(?:for|with|where|that|which|who|on|in|show|list|did|were|was)\b|[,.;])/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $token = self::extractDateToken($matches[1]);
                if ($token) {
                    $parsed = self::parseDateToken($token);
                    if ($parsed) {
                        $date = $parsed->toDateString();
                        return ['from' => $date, 'to' => $date];
                    }
                }
            }
        }

        return null;
    }

    public static function parseRelative(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return null;
        }

        // "in the last X days"
        if (preg_match('/\bin\s+the\s+last\s+(\d+)\s+days?\b/i', $normalized, $matches)) {
            $days = (int)$matches[1];
            return [
                'from' => Carbon::today()->subDays($days)->toDateString(),
                'to' => Carbon::today()->toDateString()
            ];
        }

        // "in the past X days"
        if (preg_match('/\bin\s+the\s+past\s+(\d+)\s+days?\b/i', $normalized, $matches)) {
            $days = (int)$matches[1];
            return [
                'from' => Carbon::today()->subDays($days)->toDateString(),
                'to' => Carbon::today()->toDateString()
            ];
        }

        // "last X days"
        if (preg_match('/\blast\s+(\d+)\s+days?\b/i', $normalized, $matches)) {
            $days = (int)$matches[1];
            return [
                'from' => Carbon::today()->subDays($days)->toDateString(),
                'to' => Carbon::today()->toDateString()
            ];
        }

        // "today"
        if (preg_match('/\btoday\b/i', $normalized)) {
            return [
                'from' => Carbon::today()->toDateString(),
                'to' => Carbon::today()->toDateString()
            ];
        }

        // "yesterday"
        if (preg_match('/\byesterday\b/i', $normalized)) {
            return [
                'from' => Carbon::yesterday()->toDateString(),
                'to' => Carbon::yesterday()->toDateString()
            ];
        }

        // "this week"
        if (preg_match('/\bthis\s+week\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->startOfWeek()->toDateString(),
                'to' => Carbon::now()->endOfWeek()->toDateString()
            ];
        }

        // "last week"
        if (preg_match('/\blast\s+week\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->subWeek()->startOfWeek()->toDateString(),
                'to' => Carbon::now()->subWeek()->endOfWeek()->toDateString()
            ];
        }

        // "this month"
        if (preg_match('/\bthis\s+month\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->startOfMonth()->toDateString(),
                'to' => Carbon::now()->endOfMonth()->toDateString()
            ];
        }

        // "last month"
        if (preg_match('/\blast\s+month\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->subMonth()->startOfMonth()->toDateString(),
                'to' => Carbon::now()->subMonth()->endOfMonth()->toDateString()
            ];
        }

        // "this year"
        if (preg_match('/\bthis\s+year\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->startOfYear()->toDateString(),
                'to' => Carbon::now()->endOfYear()->toDateString()
            ];
        }

        // "last year"
        if (preg_match('/\blast\s+year\b/i', $normalized)) {
            return [
                'from' => Carbon::now()->subYear()->startOfYear()->toDateString(),
                'to' => Carbon::now()->subYear()->endOfYear()->toDateString()
            ];
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        $stripped = self::stripOrdinalSuffixes($text);
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped));
        return $stripped;
    }

    private static function stripOrdinalSuffixes(string $text): string
    {
        return preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/i', '$1', $text);
    }

    private static function extractDateToken(string $fragment): ?string
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

    private static function parseDateToken(string $token): ?Carbon
    {
        try {
            return Carbon::parse($token);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function coerceDateFromPhrase(string $phrase): ?string
    {
        $p = strtolower(trim($phrase));
        if ($p === 'today') {
            return Carbon::today()->toDateString();
        }
        if ($p === 'yesterday') {
            return Carbon::yesterday()->toDateString();
        }
        try {
            $d = Carbon::parse($phrase);
            return $d->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function coerceTimeToHms(string $timeToken): ?string
    {
        $t = trim($timeToken);
        try {
            $c = Carbon::parse($t);
            return $c->format('H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}

