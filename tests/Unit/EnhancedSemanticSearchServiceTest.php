<?php

namespace Venmail\SemanticSearch\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Venmail\SemanticSearch\Core\EnhancedSemanticSearchService;
use Venmail\SemanticSearch\Tests\TestCase;

class EnhancedSemanticSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnhancedSemanticSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnhancedSemanticSearchService::class);
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $this->assertInstanceOf(EnhancedSemanticSearchService::class, $this->service);
    }

    /** @test */
    public function it_can_perform_basic_search()
    {
        $result = $this->service->search('test query');
        $this->assertInstanceOf(\Venmail\SemanticSearch\Data\SearchResult::class, $result);
        $this->assertIsArray($result->getData());
        $this->assertIsArray($result->getMetadata());
    }

    /** @test */
    public function it_provides_metrics()
    {
        $this->service->resetMetrics();
        $this->service->search('test query 1');
        $this->service->search('test query 2');

        $metrics = $this->service->getMetrics();
        $this->assertArrayHasKey('queries_processed', $metrics);
        $this->assertArrayHasKey('avg_execution_time', $metrics);
        $this->assertArrayHasKey('cache_hits', $metrics);
        $this->assertArrayHasKey('fallback_used', $metrics);
        $this->assertArrayHasKey('error_count', $metrics);
        $this->assertGreaterThanOrEqual(2, $metrics['queries_processed']);
    }

    /** @test */
    public function it_can_reset_metrics()
    {
        $this->service->search('test query');
        $this->service->resetMetrics();
        $metrics = $this->service->getMetrics();
        $this->assertEquals(0, $metrics['queries_processed']);
    }

    /** @test */
    public function it_explains_queries()
    {
        $explanation = $this->service->explainQuery('test query');
        $this->assertArrayHasKey('original_query', $explanation);
        $this->assertArrayHasKey('parsed_entities', $explanation);
        $this->assertArrayHasKey('confidence', $explanation);
        $this->assertArrayHasKey('interpretation', $explanation);
        $this->assertArrayHasKey('enhancements_applied', $explanation);
        $this->assertArrayHasKey('estimated_performance', $explanation);
    }

    /** @test */
    public function it_handles_various_query_types()
    {
        $queries = [
            'simple query',
            'mails from fred',
            'users 3 days old',
            'tasks pending'
        ];

        foreach ($queries as $query) {
            $result = $this->service->search($query);
            $this->assertInstanceOf(\Venmail\SemanticSearch\Data\SearchResult::class, $result);
            $this->assertIsArray($result->getData());
        }
    }

    /** @test */
    public function it_generates_suggestions()
    {
        $suggestions = $this->service->generateSuggestions('test query');
        $this->assertIsArray($suggestions);
    }

    /** @test */
    public function it_maintains_consistent_api()
    {
        $result = $this->service->search('test');
        $this->assertInstanceOf(\Venmail\SemanticSearch\Data\SearchResult::class, $result);
        
        $explanation = $this->service->explainQuery('test');
        $this->assertIsArray($explanation);
        
        $metrics = $this->service->getMetrics();
        $this->assertIsArray($metrics);
        
        $suggestions = $this->service->generateSuggestions('test');
        $this->assertIsArray($suggestions);
        
        $this->service->resetMetrics();
        $this->assertTrue(true);
    }
}
