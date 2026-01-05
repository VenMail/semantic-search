<?php

namespace Venmail\SemanticSearch\Core\Config;

class SemanticFieldPatterns
{
    /**
     * Common field patterns for semantic search
     * These are generic patterns that work across most projects
     */
    public static function getCommonFieldPatterns(): array
    {
        return [
            'content' => [
                'patterns' => ['content', 'body', 'message', 'text', 'description'],
                'weight' => 1.0
            ],
            'title' => [
                'patterns' => ['title', 'subject', 'heading', 'topic', 'name'],
                'weight' => 0.9
            ],
            'identifier' => [
                'patterns' => ['id', 'identifier', 'key', 'code', 'reference'],
                'weight' => 0.8
            ],
            'email' => [
                'patterns' => ['email', 'email_address', 'mail', 'address'],
                'weight' => 0.9
            ],
            'name' => [
                'patterns' => ['name', 'full_name', 'display_name', 'title'],
                'weight' => 0.8
            ],
            'date' => [
                'patterns' => ['date', 'created_at', 'updated_at', 'timestamp', 'time'],
                'weight' => 0.7
            ],
            'status' => [
                'patterns' => ['status', 'state', 'condition', 'phase'],
                'weight' => 0.6
            ],
            'category' => [
                'patterns' => ['category', 'type', 'class', 'group'],
                'weight' => 0.6
            ]
        ];
    }
    
    /**
     * Relationship patterns for semantic interpretation
     */
    public static function getRelationshipPatterns(): array
    {
        return [
            'from' => [
                'semantic' => 'sender',
                'field_patterns' => ['from', 'sender', 'author', 'creator'],
                'inverse' => 'to'
            ],
            'to' => [
                'semantic' => 'recipient',
                'field_patterns' => ['to', 'recipient', 'receiver', 'target'],
                'inverse' => 'from'
            ],
            'by' => [
                'semantic' => 'creator',
                'field_patterns' => ['by', 'creator', 'author', 'created_by'],
                'inverse' => null
            ],
            'with' => [
                'semantic' => 'associated',
                'field_patterns' => ['with', 'associated', 'related', 'linked'],
                'inverse' => 'with'
            ],
            'about' => [
                'semantic' => 'subject',
                'field_patterns' => ['about', 'subject', 'content', 'description', 'title'],
                'inverse' => null
            ],
            'in' => [
                'semantic' => 'container',
                'field_patterns' => ['in', 'category', 'folder', 'status', 'location'],
                'inverse' => null
            ],
            'for' => [
                'semantic' => 'purpose',
                'field_patterns' => ['for', 'purpose', 'target', 'intended'],
                'inverse' => null
            ],
            'on' => [
                'semantic' => 'date',
                'field_patterns' => ['on', 'date', 'created_at', 'updated_at'],
                'inverse' => null
            ],
            'at' => [
                'semantic' => 'time',
                'field_patterns' => ['at', 'time', 'created_at', 'updated_at'],
                'inverse' => null
            ],
            'during' => [
                'semantic' => 'period',
                'field_patterns' => ['during', 'period', 'range', 'between'],
                'inverse' => null
            ],
            'before' => [
                'semantic' => 'prior',
                'field_patterns' => ['before', 'prior', 'previous', 'earlier'],
                'inverse' => 'after'
            ],
            'after' => [
                'semantic' => 'subsequent',
                'field_patterns' => ['after', 'subsequent', 'later', 'following'],
                'inverse' => 'before'
            ],
            'since' => [
                'semantic' => 'from_date',
                'field_patterns' => ['since', 'from_date', 'start_date'],
                'inverse' => 'until'
            ],
            'until' => [
                'semantic' => 'to_date',
                'field_patterns' => ['until', 'to_date', 'end_date'],
                'inverse' => 'since'
            ]
        ];
    }
    
    /**
     * Priority fields for fallback search
     */
    public static function getPrioritySearchFields(): array
    {
        return [
            ['field' => 'title', 'weight' => 1.0],
            ['field' => 'subject', 'weight' => 1.0],
            ['field' => 'name', 'weight' => 0.9],
            ['field' => 'content', 'weight' => 0.8],
            ['field' => 'body', 'weight' => 0.8],
            ['field' => 'description', 'weight' => 0.8],
            ['field' => 'email', 'weight' => 0.7],
            ['field' => 'identifier', 'weight' => 0.6],
            ['field' => 'code', 'weight' => 0.6],
            ['field' => 'reference', 'weight' => 0.6]
        ];
    }
    
    /**
     * Common stop words that should be ignored in search
     */
    public static function getStopWords(): array
    {
        return [
            // Articles
            'the', 'a', 'an',
            
            // Prepositions
            'of', 'in', 'on', 'at', 'for', 'to', 'from', 'with', 'by', 'about',
            
            // Conjunctions
            'and', 'or', 'but', 'nor', 'yet', 'so',
            
            // Common verbs
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had',
            'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might',
            
            // Pronouns
            'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'him', 'her', 'us', 'them',
            
            // Quantifiers
            'all', 'every', 'each', 'any', 'some', 'multiple', 'various', 'many', 'few',
            
            // SQL keywords
            'where', 'select', 'from', 'order', 'group', 'having', 'limit', 'offset',
            'contains', 'like', 'not', 'null', 'between', 'in'
        ];
    }
    
    /**
     * Common action words
     */
    public static function getActionWords(): array
    {
        return [
            'show', 'list', 'find', 'search', 'get', 'display', 'view', 'look',
            'filter', 'sort', 'order', 'group', 'count', 'sum', 'average', 'total',
            'create', 'add', 'new', 'insert', 'make', 'build', 'generate',
            'update', 'edit', 'modify', 'change', 'alter', 'adjust',
            'delete', 'remove', 'destroy', 'eliminate', 'erase'
        ];
    }
}
