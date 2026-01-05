<?php

namespace Venmail\SemanticSearch;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;
use Venmail\SemanticSearch\Adapters\CacheAdapter;
use Venmail\SemanticSearch\Core\ContextualQueryBuilder;
use Venmail\SemanticSearch\Core\DynamicVocabularyBuilder;
use Venmail\SemanticSearch\Core\LocaleManager;
use Venmail\SemanticSearch\Core\ProjectAnalyzer;
use Venmail\SemanticSearch\Core\Validation\SQLStructureValidator;
use Venmail\SemanticSearch\Core\Fallback\AggressiveMappingFallback;
use Venmail\SemanticSearch\Core\Intelligence\RelationshipInterpreter;
use Venmail\SemanticSearch\Core\Intelligence\ActionDetector;
use Venmail\SemanticSearch\Core\Intelligence\QueryEnhancer;
use Venmail\SemanticSearch\Core\EnhancedSemanticSearchService;
use Venmail\SemanticSearch\Core\SchemaAnalyzer;
use Venmail\SemanticSearch\Core\Vocabulary;
use Venmail\SemanticSearch\Core\PendingSemanticSearch;
use Venmail\SemanticSearch\Data\SearchResult;
use Venmail\SemanticSearch\Disambiguation\SpellingCorrector;
use Venmail\SemanticSearch\Disambiguation\EntityResolver;
use Venmail\SemanticSearch\Disambiguation\ContextAnalyzer;
use Venmail\SemanticSearch\Disambiguation\SlangMemoryService;
use Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline;
use Venmail\SemanticSearch\Disambiguation\LLMFallbackAdapter;
use Venmail\SemanticSearch\Parsing\MultilingualQueryParser;
use Venmail\SemanticSearch\Parsing\MultilingualDatePhraseParser;
use Venmail\SemanticSearch\Security\PolicyGate;
use Venmail\SemanticSearch\History\QueryHistoryService;

class SemanticSearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/semantic-search.php', 'semantic-search');

        // Register LocaleManager
        $this->app->singleton(LocaleManager::class);

        // Register CacheAdapter
        $this->app->singleton(CacheAdapter::class, function ($app) {
            return new CacheAdapter(
                $app->make(Cache::class),
                config('semantic-search.cache.prefix', 'semantic_search'),
                config('semantic-search.cache.default_ttl', 3600)
            );
        });

        // Register Core Components
        $this->app->singleton(ProjectAnalyzer::class);
        $this->app->singleton(SchemaAnalyzer::class);
        $this->app->singleton(DynamicVocabularyBuilder::class);
        $this->app->singleton(Vocabulary::class);
        $this->app->singleton(ContextualQueryBuilder::class, function ($app) {
            return new ContextualQueryBuilder(
                $app->make(SchemaAnalyzer::class)
            );
        });

        // Register Validation Components
        $this->app->singleton(SQLStructureValidator::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new SQLStructureValidator($vocabulary);
        });

        // Register Intelligence Components
        $this->app->singleton(AggressiveMappingFallback::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new AggressiveMappingFallback($vocabulary);
        });

        $this->app->singleton(RelationshipInterpreter::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new RelationshipInterpreter($vocabulary);
        });

        $this->app->singleton(ActionDetector::class);
        $this->app->singleton(QueryEnhancer::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new QueryEnhancer($vocabulary);
        });

        // Register Disambiguation Components
        $this->app->singleton(SpellingCorrector::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new SpellingCorrector(
                $vocabulary,
                config('semantic-search.disambiguation.spelling_correction.max_distance', 2),
                config('semantic-search.disambiguation.spelling_correction.min_confidence', 0.6)
            );
        });

        $this->app->singleton(EntityResolver::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new EntityResolver($vocabulary);
        });

        $this->app->singleton(ContextAnalyzer::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new ContextAnalyzer($vocabulary);
        });

        $this->app->singleton(SlangMemoryService::class);
        $this->app->singleton(LLMFallbackAdapter::class);

        $this->app->singleton(DisambiguationPipeline::class, function ($app) {
            $vocabulary = $app->make(Vocabulary::class);
            return new DisambiguationPipeline(
                $app->make(SpellingCorrector::class),
                $app->make(EntityResolver::class),
                $app->make(ContextAnalyzer::class),
                $app->make(SlangMemoryService::class),
                $vocabulary
            );
        });

        // Register Parsing Components
        $this->app->singleton(MultilingualQueryParser::class, function ($app) {
            return new MultilingualQueryParser(
                $app->make(LocaleManager::class),
                $app->make(Vocabulary::class)
            );
        });

        $this->app->singleton(MultilingualDatePhraseParser::class, function ($app) {
            return new MultilingualDatePhraseParser(
                $app->make(LocaleManager::class)
            );
        });

        // Register Security and History
        $this->app->singleton(PolicyGate::class);
        $this->app->singleton(QueryHistoryService::class);

        // Register Enhanced Semantic Search Service (Main Entry Point)
        $this->app->singleton(EnhancedSemanticSearchService::class, function ($app) {
            return new EnhancedSemanticSearchService(
                $app->make(Vocabulary::class),
                $app->make(SQLStructureValidator::class),
                $app->make(AggressiveMappingFallback::class),
                $app->make(RelationshipInterpreter::class),
                $app->make(ActionDetector::class),
                $app->make(QueryEnhancer::class),
                $app->make(DisambiguationPipeline::class),
                $app->make(MultilingualQueryParser::class),
                $app->make(CacheAdapter::class),
                $app->make(Cache::class)
            );
        });

        // Register PendingSemanticSearch for fluent interface
        $this->app->bind(PendingSemanticSearch::class, function ($app) {
            return new PendingSemanticSearch(
                $app->make(EnhancedSemanticSearchService::class),
                '' // Empty query, will be set when query() is called
            );
        });
    }

    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/semantic-search.php' => config_path('semantic-search.php'),
        ], 'semantic-search-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'semantic-search-migrations');

        // Register facade
        $this->app->singleton('semantic-search', function ($app) {
            return $app->make(EnhancedSemanticSearchService::class);
        });
    }
}
