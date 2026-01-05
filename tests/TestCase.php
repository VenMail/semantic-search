<?php

namespace Venmail\SemanticSearch\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Venmail\SemanticSearch\SemanticSearchServiceProvider;
use Venmail\SemanticSearch\Core\ProjectAnalyzer;
use Venmail\SemanticSearch\Core\DynamicVocabularyBuilder;
use Venmail\SemanticSearch\Data\ProjectMetadata;
use Venmail\SemanticSearch\Core\EnhancedSemanticSearchService;
use Venmail\SemanticSearch\Data\SearchResult;
use Venmail\SemanticSearch\Tests\Models\User;
use Venmail\SemanticSearch\Tests\Models\Post;
use Venmail\SemanticSearch\Tests\Models\Comment;
use Venmail\SemanticSearch\Tests\Models\SensitiveData;
use Venmail\SemanticSearch\Tests\Models\Mail;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function searchEngine(): EnhancedSemanticSearchService
    {
        return app(EnhancedSemanticSearchService::class);
    }

    protected function search(string $query, array $options = []): SearchResult
    {
        return $this->searchEngine()->search($query, $options);
    }

    protected function getPackageProviders($app)
    {
        return [
            SemanticSearchServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup semantic search config
        $app['config']->set('semantic-search.cache.enabled', true);
        $app['config']->set('semantic-search.cache.default_ttl', 3600);
        $app['config']->set('semantic-search.disambiguation.enabled', false); // Disable for basic tests
        $app['config']->set('semantic-search.history.enabled', false); // Disable for basic tests
        $app['config']->set('semantic-search.performance.max_query_time', 30);
        $app['config']->set('semantic-search.analysis.scan_directories', [
            'controllers' => __DIR__,
            'models' => __DIR__,
            'views' => __DIR__,
            'routes' => __DIR__,
        ]);

        // Bind in-memory ProjectMetadata & Vocabulary builders for tests
        $app->singleton(ProjectAnalyzer::class, function () {
            $metadata = new ProjectMetadata();
            $metadata->addModel(User::class, [
                'attributes' => [
                    'name' => 'string',
                    'email' => 'string',
                    'age' => 'integer',
                    'active' => 'boolean',
                ],
                'relationships' => [
                    'posts' => ['type' => 'hasMany'],
                    'comments' => ['type' => 'hasMany'],
                ],
            ]);

            $metadata->addModel(Post::class, [
                'attributes' => [
                    'title' => 'string',
                    'content' => 'text',
                    'user_id' => 'integer',
                ],
                'relationships' => [
                    'comments' => ['type' => 'hasMany'],
                ],
            ]);

            $metadata->addModel(Comment::class, [
                'attributes' => [
                    'content' => 'text',
                    'user_id' => 'integer',
                    'post_id' => 'integer',
                ],
            ]);

            $metadata->addModel(SensitiveData::class, [
                'attributes' => [
                    'secret' => 'string',
                    'private_info' => 'text',
                    'user_id' => 'integer',
                ],
            ]);

            $metadata->addModel(Mail::class, [
                'attributes' => [
                    'subject' => 'string',
                    'plain_body' => 'text',
                    'sender_name' => 'string',
                    'sender_email' => 'string',
                ],
            ]);

            return new class($metadata) extends ProjectAnalyzer {
                public function __construct(private ProjectMetadata $metadata) {}
                public function analyze(): ProjectMetadata
                {
                    return $this->metadata;
                }
            };
        });

        $app->singleton(DynamicVocabularyBuilder::class, function () {
            return new class extends DynamicVocabularyBuilder {
                public function buildVocabulary(ProjectMetadata $metadata): \Venmail\SemanticSearch\Core\Vocabulary
                {
                    $vocabulary = parent::buildVocabulary($metadata);

                    // Ensure baseline action words and locale variations for tests
                    $vocabulary->addActionMapping('show', 'show', ['list', 'display', 'listar']);
                    $vocabulary->addActionMapping('mostrar', 'show', ['muéstrame']);

                    // Add locale-specific model synonyms
                    $vocabulary->addModelMapping('usuarios', User::class, ['usuario', 'usuarios']);
                    $vocabulary->addModelMapping('datos_sensibles', SensitiveData::class, ['dato_sensible', 'datos_sensibles']);
                    $vocabulary->addModelMapping('mail', Mail::class, ['mail', 'mails', 'email', 'emails', 'meeting', 'meetings', 'message', 'messages']);

                    return $vocabulary;
                }
            };
        });
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
