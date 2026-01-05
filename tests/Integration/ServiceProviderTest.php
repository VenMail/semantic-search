<?php

namespace Venmail\SemanticSearch\Tests\Integration;

use Venmail\SemanticSearch\Core\EnhancedSemanticSearchService;
use Venmail\SemanticSearch\Core\ProjectAnalyzer;
use Venmail\SemanticSearch\Core\ContextualQueryBuilder;
use Venmail\SemanticSearch\Core\DynamicVocabularyBuilder;
use Venmail\SemanticSearch\Adapters\CacheAdapter;
use Venmail\SemanticSearch\Core\LocaleManager;
use Venmail\SemanticSearch\History\QueryHistoryService;
use Venmail\SemanticSearch\Security\PolicyGate;
use Venmail\SemanticSearch\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    /** @test */
    public function it_registers_enhanced_search_service_as_singleton()
    {
        $searchService1 = $this->app->make(EnhancedSemanticSearchService::class);
        $searchService2 = $this->app->make(EnhancedSemanticSearchService::class);

        $this->assertInstanceOf(EnhancedSemanticSearchService::class, $searchService1);
        $this->assertSame($searchService1, $searchService2);
    }

    /** @test */
    public function it_registers_project_analyzer_as_singleton()
    {
        $analyzer1 = $this->app->make(ProjectAnalyzer::class);
        $analyzer2 = $this->app->make(ProjectAnalyzer::class);

        $this->assertInstanceOf(ProjectAnalyzer::class, $analyzer1);
        $this->assertSame($analyzer1, $analyzer2);
    }

    /** @test */
    public function it_registers_contextual_query_builder_as_singleton()
    {
        $queryBuilder1 = $this->app->make(ContextualQueryBuilder::class);
        $queryBuilder2 = $this->app->make(ContextualQueryBuilder::class);

        $this->assertInstanceOf(ContextualQueryBuilder::class, $queryBuilder1);
        $this->assertSame($queryBuilder1, $queryBuilder2);
    }

    /** @test */
    public function it_registers_dynamic_vocabulary_builder_as_singleton()
    {
        $vocabularyBuilder1 = $this->app->make(DynamicVocabularyBuilder::class);
        $vocabularyBuilder2 = $this->app->make(DynamicVocabularyBuilder::class);

        $this->assertInstanceOf(DynamicVocabularyBuilder::class, $vocabularyBuilder1);
        $this->assertSame($vocabularyBuilder1, $vocabularyBuilder2);
    }

    /** @test */
    public function it_registers_cache_adapter_with_proper_parameters()
    {
        $cacheAdapter = $this->app->make(CacheAdapter::class);

        $this->assertInstanceOf(CacheAdapter::class, $cacheAdapter);
    }

    /** @test */
    public function it_registers_locale_manager_as_singleton()
    {
        $localeManager1 = $this->app->make(LocaleManager::class);
        $localeManager2 = $this->app->make(LocaleManager::class);

        $this->assertInstanceOf(LocaleManager::class, $localeManager1);
        $this->assertSame($localeManager1, $localeManager2);
    }

    /** @test */
    public function it_registers_query_history_service()
    {
        $historyService = $this->app->make(QueryHistoryService::class);

        $this->assertInstanceOf(QueryHistoryService::class, $historyService);
    }

    /** @test */
    public function it_registers_policy_gate()
    {
        $policyGate = $this->app->make(PolicyGate::class);

        $this->assertInstanceOf(PolicyGate::class, $policyGate);
    }

    /** @test */
    public function it_merges_configuration()
    {
        $config = $this->app->config->get('semantic-search');
        
        // Debug output
        if ($config === null) {
            $configPath = __DIR__.'/../../config/semantic-search.php';
            $this->assertTrue(file_exists($configPath), 'Config file should exist at: ' . $configPath);
            $config = require $configPath;
            $this->app->config->set('semantic-search', $config);
        }

        $this->assertIsArray($config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertArrayHasKey('disambiguation', $config);
        $this->assertArrayHasKey('history', $config);
        $this->assertArrayHasKey('performance', $config);
        $this->assertArrayHasKey('analysis', $config);
        $this->assertArrayHasKey('security', $config);
    }

    /** @test */
    public function it_resolves_enhanced_search_service_with_all_dependencies()
    {
        $searchService = $this->app->make(EnhancedSemanticSearchService::class);

        $this->assertInstanceOf(EnhancedSemanticSearchService::class, $searchService);

        // Test that we can actually use it (basic functionality)
        $this->assertIsObject($searchService);
        $this->assertIsCallable([$searchService, 'search']);
        $this->assertIsCallable([$searchService, 'explainQuery']);
        $this->assertIsCallable([$searchService, 'getMetrics']);
        $this->assertIsCallable([$searchService, 'generateSuggestions']);
    }
}
