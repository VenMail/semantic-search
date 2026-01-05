# Development Guide

This guide helps developers set up the Laravel Semantic Search package locally for testing and development.

## 📋 Prerequisites

- PHP 8.1 or higher
- Composer
- Laravel 9.x, 10.x, or 11.x
- MySQL 8.0+, PostgreSQL 12+, or SQLite 3.0+
- Git (for cloning)

## 🚀 Local Setup

### Option 1: Clone Repository

```bash
# Clone the repository
git clone https://github.com/venmail/laravel-semantic-search.git
cd laravel-semantic-search

# Install dependencies
composer install
```

### Option 2: Copy to Laravel Project

```bash
# In your Laravel project root
mkdir -p packages/venmail
cd packages/venmail

# Copy the semsearch folder here
# Or clone directly:
git clone https://github.com/venmail/laravel-semantic-search.git semantic-search
cd semantic-search
composer install
```

## 🔧 Integration with Laravel Project

### 1. Add to composer.json

Add the local package to your Laravel project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/venmail/semantic-search"
        }
    ],
    "require": {
        "venmail/laravel-semantic-search": "*"
    }
}
```

Then run:

```bash
composer update venmail/laravel-semantic-search
```

### 2. Register Service Provider

The package uses auto-discovery, but you can manually register it in `config/app.php`:

```php
'providers' => [
    // ...
    Venmail\SemanticSearch\SemanticSearchServiceProvider::class,
],
```

### 3. Publish Configuration

```bash
php artisan vendor:publish --tag="semantic-search-config"
```

### 4. Configure Environment

Add to your `.env`:

```env
SEMANTIC_SEARCH_CACHE_ENABLED=true
SEMANTIC_SEARCH_CACHE_TTL=3600
SEMANTIC_SEARCH_DISAMBIGUATION_ENABLED=true

# Optional: LLM Fallback
SEMANTIC_SEARCH_LLM_ENABLED=false
OPENAI_API_KEY=
```

## 🧪 Testing

### Create Test Models

Create some test models in your Laravel application:

```php
<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email', 'age', 'active'];
    
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
    
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
```

```php
<?php
// app/Models/Post.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $fillable = ['title', 'content', 'user_id', 'created_at'];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

### Create Test Data

```php
// database/seeders/TestDataSeeder.php
use App\Models\User;
use App\Models\Post;

User::factory()->create(['name' => 'John Doe', 'age' => 30, 'active' => true]);
User::factory()->create(['name' => 'Jane Smith', 'age' => 25, 'active' => true]);
User::factory()->create(['name' => 'Bob Wilson', 'age' => 35, 'active' => false]);

$user = User::first();
Post::factory()->create(['user_id' => $user->id, 'title' => 'My First Post']);
```

### Test Queries

Create a test route or controller:

```php
// routes/web.php or routes/api.php
use Venmail\SemanticSearch\Facades\SemanticSearch;

Route::get('/test-search', function () {
    // Simple query
    $results = SemanticSearch::search("active users");
    return response()->json($results);
    
    // Filtered query
    $results = SemanticSearch::search("users older than 25");
    return response()->json($results);
    
    // Relationship query
    $results = SemanticSearch::search("users with posts");
    return response()->json($results);
    
    // Aggregation
    $results = SemanticSearch::search("count users");
    return response()->json($results);
});
```

## 🔍 Debugging

### Enable Debug Mode

Add to `config/semantic-search.php`:

```php
'debug' => [
    'enabled' => true,
    'log_queries' => true,
    'log_performance' => true,
],
```

### View Logs

Check `storage/logs/laravel.log` for:
- Query parsing details
- LLM fallback calls
- Performance metrics
- Error messages

### Inspect Parsed Queries

```php
use Venmail\SemanticSearch\Core\SearchEngine;
use Venmail\SemanticSearch\Core\ProjectAnalyzer;
use Venmail\SemanticSearch\Core\DynamicVocabularyBuilder;
use Venmail\SemanticSearch\Parsing\MultilingualQueryParser;
use Venmail\SemanticSearch\Core\LocaleManager;

$analyzer = app(ProjectAnalyzer::class);
$metadata = $analyzer->analyze();

$vocabularyBuilder = app(DynamicVocabularyBuilder::class);
$vocabulary = $vocabularyBuilder->buildVocabulary($metadata);

$parser = new MultilingualQueryParser(
    app(LocaleManager::class),
    $vocabulary
);

$parsed = $parser->parse("active users older than 25", 'en');

// Inspect parsed query
dd($parsed);
```

## 🛠️ Development Workflow

### Making Changes

1. **Edit Source Files**
   - Make changes in `src/` directory
   - Follow PSR-12 coding standards
   - Add type hints and docblocks

2. **Test Locally**
   - Run test queries in your Laravel app
   - Check logs for errors
   - Verify query results

3. **Clear Cache**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

4. **Test Different Scenarios**
   - Simple queries
   - Complex queries
   - Multilingual queries
   - Aggregation queries
   - LLM fallback (if enabled)

### Code Structure

```
src/
├── Core/              # Core functionality
│   ├── SearchEngine.php
│   ├── ProjectAnalyzer.php
│   ├── ContextualQueryBuilder.php
│   ├── SchemaAnalyzer.php
│   └── Database/      # Database adapters
├── Parsing/           # Query parsing
├── Disambiguation/    # Query disambiguation
├── Data/              # Data structures
├── Security/          # Security & authorization
├── History/           # Query history
└── Adapters/          # External adapters
```

## 🧪 Running Tests

### Setup Test Environment

```bash
# Install test dependencies
composer require --dev phpunit/phpunit orchestra/testbench

# Create test database
php artisan migrate --database=testing
```

### Write Tests

Create tests in `tests/` directory:

```php
<?php
// tests/Feature/SemanticSearchTest.php
namespace Tests\Feature;

use Tests\TestCase;
use Venmail\SemanticSearch\Facades\SemanticSearch;

class SemanticSearchTest extends TestCase
{
    public function test_simple_query()
    {
        $results = SemanticSearch::search("users");
        
        $this->assertNotEmpty($results->getData());
    }
    
    public function test_filtered_query()
    {
        $results = SemanticSearch::search("active users");
        
        $this->assertNotEmpty($results->getData());
    }
}
```

### Run Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test
vendor/bin/phpunit tests/Feature/SemanticSearchTest.php
```

## 🐛 Common Issues

### Issue: Package not found

**Solution:**
```bash
composer dump-autoload
php artisan config:clear
```

### Issue: Models not discovered

**Solution:**
- Check `config/semantic-search.php` scan directories
- Verify models are in correct namespace
- Clear cache: `php artisan cache:clear`

### Issue: Database errors

**Solution:**
- Verify database connection
- Check database driver is supported (mysql, pgsql, sqlite)
- Review `SchemaAnalyzer` logs

### Issue: LLM not working

**Solution:**
- Verify `OPENAI_API_KEY` is set
- Check `SEMANTIC_SEARCH_LLM_ENABLED=true`
- Review network connectivity
- Check API quota/limits

## 📝 Development Checklist

Before submitting changes:

- [ ] Code follows PSR-12 standards
- [ ] All tests pass
- [ ] Documentation updated
- [ ] No linter errors
- [ ] Tested with multiple Laravel versions
- [ ] Tested with multiple database drivers
- [ ] Security reviewed
- [ ] Performance tested

## 🔗 Useful Commands

```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Re-analyze project
# (Cache will be cleared on next search)

# View package configuration
php artisan config:show semantic-search

# Test database connection
php artisan tinker
>>> DB::connection()->getPdo();
```

## 📚 Additional Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Package Development Guide](https://laravel.com/docs/packages)
- [PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Write/update tests
5. Submit a pull request

## 📞 Getting Help

- Check existing issues on GitHub
- Review documentation
- Ask in discussions
- Contact maintainers

---

Happy coding! 🚀

