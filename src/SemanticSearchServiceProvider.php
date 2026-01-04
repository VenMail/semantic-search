<?php

namespace Venmail\SemanticSearch;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;
use Venmail\SemanticSearch\Adapters\CacheAdapter;
use Venmail\SemanticSearch\Core\ContextualQueryBuilder;
use Venmail\SemanticSearch\Core\DynamicVocabularyBuilder;
use Venmail\SemanticSearch\Core\LocaleManager;
use Venmail\SemanticSearch\Core\ProjectAnalyzer;
use Venmail\SemanticSearch\Core\SearchEngine;

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

        // Register ProjectAnalyzer
        $this->app->singleton(ProjectAnalyzer::class);

        // Register SchemaAnalyzer
        $this->app->singleton(\Venmail\SemanticSearch\Core\SchemaAnalyzer::class);

        // Register ContextualQueryBuilder
        $this->app->singleton(ContextualQueryBuilder::class, function ($app) {
            return new ContextualQueryBuilder(
                $app->make(\Venmail\SemanticSearch\Core\SchemaAnalyzer::class)
            );
        });

        // Register DynamicVocabularyBuilder
        $this->app->singleton(DynamicVocabularyBuilder::class);

        // Register Vocabulary
        $this->app->singleton(\Venmail\SemanticSearch\Core\Vocabulary::class);

        // Register PolicyGate
        $this->app->singleton(\Venmail\SemanticSearch\Security\PolicyGate::class);

        // Register QueryHistoryService
        $this->app->singleton(\Venmail\SemanticSearch\History\QueryHistoryService::class);

        // Register Disambiguation components
        $this->app->singleton(\Venmail\SemanticSearch\Disambiguation\SpellingCorrector::class, function ($app) {
            $vocabulary = $app->make(\Venmail\SemanticSearch\Core\Vocabulary::class);
            return new \Venmail\SemanticSearch\Disambiguation\SpellingCorrector(
                $vocabulary,
                config('semantic-search.disambiguation.spelling_correction.max_distance', 2),
                config('semantic-search.disambiguation.spelling_correction.min_confidence', 0.6)
            );
        });

        $this->app->singleton(\Venmail\SemanticSearch\Disambiguation\EntityResolver::class, function ($app) {
            $vocabulary = $app->make(\Venmail\SemanticSearch\Core\Vocabulary::class);
            return new \Venmail\SemanticSearch\Disambiguation\EntityResolver($vocabulary);
        });

        $this->app->singleton(\Venmail\SemanticSearch\Disambiguation\ContextAnalyzer::class, function ($app) {
            $vocabulary = $app->make(\Venmail\SemanticSearch\Core\Vocabulary::class);
            return new \Venmail\SemanticSearch\Disambiguation\ContextAnalyzer($vocabulary);
        });

        $this->app->singleton(\Venmail\SemanticSearch\Disambiguation\SlangMemoryService::class);

        $this->app->singleton(\Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline::class, function ($app) {
            $vocabulary = $app->make(\Venmail\SemanticSearch\Core\Vocabulary::class);
            return new \Venmail\SemanticSearch\Disambiguation\DisambiguationPipeline(
                $app->make(\Venmail\SemanticSearch\Disambiguation\SpellingCorrector::class),
                $app->make(\Venmail\SemanticSearch\Disambiguation\EntityResolver::class),
                $app->make(\Venmail\SemanticSearch\Disambiguation\ContextAnalyzer::class),
                $app->make(\Venmail\SemanticSearch\Disambiguation\SlangMemoryService::class),
                $vocabulary
            );
        });

        // Register SearchEngine
        $this->app->singleton(SearchEngine::class, function ($app) {
            return new SearchEngine(
                $app->make(ProjectAnalyzer::class),
                $app->make(ContextualQueryBuilder::class),
                $app->make(DynamicVocabularyBuilder::class),
                $app->make(CacheAdapter::class),
                $app->make(Cache::class),
                $app->make(\Venmail\SemanticSearch\Security\PolicyGate::class),
                $app->make(\Venmail\SemanticSearch\History\QueryHistoryService::class),
                $app->make(LocaleManager::class)
            );
        });

        // Register facade binding
        $this->app->bind('semantic-search', function ($app) {
            return $app->make(SearchEngine::class);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/semantic-search.php' => config_path('semantic-search.php'),
        ], 'semantic-search-config');
    }
}
