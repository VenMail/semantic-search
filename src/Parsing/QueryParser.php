<?php

namespace Venmail\SemanticSearch\Parsing;

use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Data\ParsedQuery;

class QueryParser
{
    private MultilingualQueryParser $multilingualParser;
    
    public function __construct(Vocabulary $vocabulary)
    {
        $this->multilingualParser = new MultilingualQueryParser(
            app(\Venmail\SemanticSearch\Core\LocaleManager::class),
            $vocabulary
        );
    }
    
    public function parse(string $query): ParsedQuery
    {
        // Use multilingual parser with English as default locale
        return $this->multilingualParser->parse($query, 'en');
    }
}


