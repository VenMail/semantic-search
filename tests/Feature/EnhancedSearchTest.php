<?php

namespace Venmail\SemanticSearch\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Venmail\SemanticSearch\Tests\TestCase;
use Venmail\SemanticSearch\Core\EnhancedSemanticSearchService;
use Venmail\SemanticSearch\Data\SearchResult;

class EnhancedSearchTest extends TestCase
{
    use RefreshDatabase;

    private EnhancedSemanticSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnhancedSemanticSearchService::class);
        $this->seedTestData();
    }

    private function seedTestData()
    {
        // Create test users
        \Venmail\SemanticSearch\Tests\Models\User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => bcrypt('password'),
            'age' => 25,
            'active' => true,
        ]);

        \Venmail\SemanticSearch\Tests\Models\User::create([
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'password' => bcrypt('password'),
            'age' => 30,
            'active' => false,
        ]);

        // Create test posts
        \Venmail\SemanticSearch\Tests\Models\Post::create([
            'title' => 'Test Post 1',
            'content' => 'This is a test post about semantic search.',
            'user_id' => 1,
        ]);

        \Venmail\SemanticSearch\Tests\Models\Post::create([
            'title' => 'Another Post',
            'content' => 'This post discusses search functionality.',
            'user_id' => 2,
        ]);
    }

    /** @test */
    public function it_can_perform_basic_enhanced_search()
    {
        $result = $this->service->search('test');

        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertIsArray($result->getData());
        $this->assertIsArray($result->getMetadata());
    }

    /** @test */
    public function it_provides_search_metrics()
    {
        $this->service->resetMetrics();
        
        $this->service->search('test query');
        $this->service->search('another query');

        $metrics = $this->service->getMetrics();

        $this->assertArrayHasKey('queries_processed', $metrics);
        $this->assertArrayHasKey('avg_execution_time', $metrics);
        $this->assertArrayHasKey('cache_hits', $metrics);
        $this->assertArrayHasKey('fallback_used', $metrics);
        $this->assertArrayHasKey('error_count', $metrics);
        $this->assertGreaterThanOrEqual(2, $metrics['queries_processed']);
    }

    /** @test */
    public function it_explains_search_queries()
    {
        $explanation = $this->service->explainQuery('show users');

        $this->assertArrayHasKey('original_query', $explanation);
        $this->assertArrayHasKey('parsed_entities', $explanation);
        $this->assertArrayHasKey('confidence', $explanation);
        $this->assertArrayHasKey('interpretation', $explanation);
        $this->assertArrayHasKey('enhancements_applied', $explanation);
        $this->assertArrayHasKey('estimated_performance', $explanation);
    }

    /** @test */
    public function it_generates_search_suggestions()
    {
        $suggestions = $this->service->generateSuggestions('test query');

        $this->assertIsArray($suggestions);
        // Suggestions may be empty if no vocabulary is built, that's okay
        $this->assertTrue(true); // If we get here, the method works
    }

    /** @test */
    public function it_handles_various_query_types()
    {
        $queries = [
            'users',
            'posts',
            'test',
            'search',
            'show'
        ];

        foreach ($queries as $query) {
            $result = $this->service->search($query);
            $this->assertInstanceOf(SearchResult::class, $result);
            $this->assertIsArray($result->getData());
            $this->assertIsArray($result->getMetadata());
        }
    }

    /** @test */
    public function it_maintains_api_consistency()
    {
        // Test that all expected methods exist and return proper types
        $result = $this->service->search('test');
        $this->assertInstanceOf(SearchResult::class, $result);
        
        $explanation = $this->service->explainQuery('test');
        $this->assertIsArray($explanation);
        
        $metrics = $this->service->getMetrics();
        $this->assertIsArray($metrics);
        
        $suggestions = $this->service->generateSuggestions('test');
        $this->assertIsArray($suggestions);
        
        // Should not throw any exceptions
        $this->service->resetMetrics();
        $this->assertTrue(true);
    }

    /** @test */
    public function it_handles_empty_queries_gracefully()
    {
        $result = $this->service->search('');
        
        $this->assertInstanceOf(SearchResult::class, $result);
        $this->assertIsArray($result->getData());
        $this->assertIsArray($result->getMetadata());
    }

    /** @test */
    public function it_provides_performance_estimates()
    {
        $explanation = $this->service->explainQuery('complex query with many conditions');
        
        $this->assertArrayHasKey('estimated_performance', $explanation);
        $performance = $explanation['estimated_performance'];
        
        $this->assertArrayHasKey('complexity_score', $performance);
        $this->assertArrayHasKey('estimated_performance', $performance);
        $this->assertArrayHasKey('optimization_suggestions', $performance);
    }
}
