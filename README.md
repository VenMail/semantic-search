# Laravel Semantic Search

[![Latest Version](https://img.shields.io/packagist/v/venmail/laravel-semantic-search.svg?style=flat-square)](https://packagist.org/packages/venmail/laravel-semantic-search)
[![Total Downloads](https://img.shields.io/packagist/dt/venmail/laravel-semantic-search.svg?style=flat-square)](https://packagist.org/packages/venmail/laravel-semantic-search)
[![License](https://img.shields.io/packagist/l/venmail/laravel-semantic-search.svg?style=flat-square)](https://packagist.org/packages/venmail/laravel-semantic-search)

A powerful, production-ready semantic search package for Laravel that enables natural language querying across your Eloquent models with intelligent English-to-SQL translation. No embeddings, no ML training—just instant, accurate search powered by static code analysis.

## ✨ Features

- 🎯 **Natural Language Queries** - Query your models using plain English
- 🧠 **Intelligent Code Analysis** - Automatically analyzes your Laravel project structure
- 🌍 **Multilingual Support** - English, Spanish, French, German, Portuguese
- 🔍 **Context-Aware Search** - Understands business logic, relationships, and UI patterns
- 🔗 **Relationship Intelligence** - Navigates Eloquent relationships automatically
- 🚀 **Zero Training Required** - Works instantly with your existing codebase
- 💾 **Multi-Database Support** - MySQL, PostgreSQL, SQLite
- 🤖 **LLM Fallback** - Optional OpenAI integration for complex queries
- 🔒 **Security First** - Built-in authorization and access control
- ⚡ **Performance Optimized** - Intelligent caching and query optimization
- 📊 **Aggregation Support** - Count, sum, average, min, max with grouping
- 🔀 **Boolean Logic** - AND/OR operators in filters

## 📋 Requirements

- PHP 8.1 or higher
- Laravel 9.x, 10.x, or 11.x
- MySQL 8.0+, PostgreSQL 12+, or SQLite 3.0+
- Composer

## 📦 Installation

### Install via Composer

```bash
composer require venmail/laravel-semantic-search
```

### Publish Configuration

```bash
php artisan vendor:publish --tag="semantic-search-config"
```

This will create `config/semantic-search.php` in your Laravel application.

## 🚀 Quick Start

### Basic Usage

```php
use Venmail\SemanticSearch\Facades\SemanticSearch;

// Simple queries
$results = SemanticSearch::search("active users older than 25");
$results = SemanticSearch::search("users with posts created last month");

// With pagination
$results = SemanticSearch::search("high-value transactions")
    ->paginate(20);

// Aggregation queries
$results = SemanticSearch::search("count users by country");
$results = SemanticSearch::search("sum of transactions by category");
```

### Example Controller

```php
<?php

namespace App\Http\Controllers;

use Venmail\SemanticSearch\Facades\SemanticSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q');
        
        $results = SemanticSearch::search($query, [
            'paginate' => 20,
            'locale' => app()->getLocale(),
        ]);
        
        return response()->json($results);
    }
}
```

## 🎯 Supported Query Types

### Simple Entity Queries
```
"show users"
"list orders"
"display products"
```

### Filtered Queries
```
"users older than 25"
"transactions over $1000"
"posts created last month"
"active users with email containing @example.com"
```

### Relationship Queries
```
"users with posts"
"customers and their orders"
"products with reviews"
"users with posts that have comments"
```

### Aggregation Queries
```
"count users"
"sum of transactions by category"
"average age of users"
"total revenue by month"
"count orders by status"
```

### Complex Queries
```
"active users who made purchases over $500 in the last 30 days"
"customers with more than 5 orders and total spending over $1000"
"users with posts that have more than 100 likes"
```

### Boolean Logic
```
"users with posts and comments" (AND)
"active users or premium users" (OR)
```

## ⚙️ Configuration

### Basic Configuration

Edit `config/semantic-search.php`:

```php
return [
    'analysis' => [
        'auto_discover' => true,
        'scan_directories' => [
            'controllers' => app_path('Http/Controllers'),
            'models' => app_path('Models'),
            'views' => resource_path('views'),
            'routes' => base_path('routes'),
        ],
    ],

    'cache' => [
        'enabled' => env('SEMANTIC_SEARCH_CACHE_ENABLED', true),
        'default_ttl' => env('SEMANTIC_SEARCH_CACHE_TTL', 3600),
        'prefix' => env('SEMANTIC_SEARCH_CACHE_PREFIX', 'semantic_search'),
    ],

    'llm' => [
        'enabled' => env('SEMANTIC_SEARCH_LLM_ENABLED', false),
        'provider' => env('SEMANTIC_SEARCH_LLM_PROVIDER', 'openai'),
        'model' => env('SEMANTIC_SEARCH_LLM_MODEL', 'gpt-3.5-turbo'),
        'api_key' => env('OPENAI_API_KEY'),
        'timeout' => env('SEMANTIC_SEARCH_LLM_TIMEOUT', 10),
        'confidence_threshold' => env('SEMANTIC_SEARCH_LLM_CONFIDENCE', 0.7),
        'cache_ttl' => env('SEMANTIC_SEARCH_LLM_CACHE_TTL', 3600),
    ],

    'security' => [
        'max_rows' => env('SEMANTIC_SEARCH_MAX_ROWS', 10000),
        'restricted_models' => env('SEMANTIC_SEARCH_RESTRICTED_MODELS', '') 
            ? explode(',', env('SEMANTIC_SEARCH_RESTRICTED_MODELS')) 
            : [],
        'restricted_fields' => env('SEMANTIC_SEARCH_RESTRICTED_FIELDS', '') 
            ? explode(',', env('SEMANTIC_SEARCH_RESTRICTED_FIELDS')) 
            : [],
    ],

    'disambiguation' => [
        'enabled' => env('SEMANTIC_SEARCH_DISAMBIGUATION_ENABLED', true),
        'confidence_threshold' => env('SEMANTIC_SEARCH_DISAMBIGUATION_CONFIDENCE', 0.6),
    ],

    'performance' => [
        'max_query_time' => env('SEMANTIC_SEARCH_MAX_QUERY_TIME', 30),
        'memory_limit' => env('SEMANTIC_SEARCH_MEMORY_LIMIT', '256M'),
    ],
];
```

### Environment Variables

Add to your `.env`:

```env
# Cache
SEMANTIC_SEARCH_CACHE_ENABLED=true
SEMANTIC_SEARCH_CACHE_TTL=3600

# LLM (Optional)
SEMANTIC_SEARCH_LLM_ENABLED=false
OPENAI_API_KEY=your_api_key_here
SEMANTIC_SEARCH_LLM_MODEL=gpt-3.5-turbo

# Security
SEMANTIC_SEARCH_MAX_ROWS=10000
SEMANTIC_SEARCH_RESTRICTED_MODELS=
SEMANTIC_SEARCH_RESTRICTED_FIELDS=

# Performance
SEMANTIC_SEARCH_MAX_QUERY_TIME=30
```

## 🌍 Multilingual Support

The package supports multiple languages out of the box:

```php
// English (default)
SemanticSearch::search("show active users", ['locale' => 'en']);

// Spanish
SemanticSearch::search("mostrar usuarios activos", ['locale' => 'es']);

// French
SemanticSearch::search("afficher les utilisateurs actifs", ['locale' => 'fr']);

// German
SemanticSearch::search("aktive Benutzer anzeigen", ['locale' => 'de']);

// Portuguese
SemanticSearch::search("mostrar usuários ativos", ['locale' => 'pt']);
```

## 🤖 LLM Fallback (Optional)

For complex queries that the static parser cannot handle, enable LLM fallback:

```env
SEMANTIC_SEARCH_LLM_ENABLED=true
OPENAI_API_KEY=your_api_key_here
```

The LLM fallback automatically activates when:
- Query confidence is below threshold
- Complex multi-clause queries
- Ambiguous entity names
- Domain-specific terminology

## 🔒 Security

### Access Control

The package includes built-in security features:

```php
// Restrict access to specific models
'restricted_models' => [
    'App\Models\SensitiveData',
    'App\Models\AdminUser',
],

// Restrict access to sensitive fields
'restricted_fields' => [
    'password',
    'secret_key',
    'credit_card',
],
```

### Authorization

Queries are automatically checked against:
- Model access restrictions
- Field access restrictions
- Relationship traversal permissions
- Row limit constraints

## 📊 Advanced Usage

### Custom Query Options

```php
$results = SemanticSearch::search("users", [
    'paginate' => 20,        // Pagination
    'limit' => 100,          // Limit results
    'locale' => 'en',         // Language
]);
```

### Query History

```php
// Get user's search history
$history = SemanticSearch::getHistory(limit: 20);

// Get suggestions based on history
$suggestions = SemanticSearch::getSuggestions("user", limit: 5);
```

### Cache Management

```php
// Clear all cache
SemanticSearch::clearCache();

// Clear specific pattern
SemanticSearch::clearCache('users:*');
```

## 🏗️ Architecture

The package uses intelligent static code analysis to understand your Laravel application:

1. **Project Analysis** - Scans controllers, models, views, and routes
2. **Vocabulary Building** - Creates dynamic vocabulary from your codebase
3. **Query Parsing** - Parses natural language into structured queries
4. **Disambiguation** - Handles spelling, entities, and context
5. **Query Building** - Translates to optimized Eloquent queries
6. **Execution** - Runs queries with proper security and caching

## 🔧 Database Support

The package supports multiple database engines:

- **MySQL 8.0+** - Full support
- **PostgreSQL 12+** - Full support
- **SQLite 3.0+** - Full support

Database-specific optimizations are automatically applied.

## 📈 Performance

### Caching Strategy

- **Metadata Cache** - Project analysis results (4 hours)
- **Schema Cache** - Database schema information (4 hours)
- **Query Cache** - Search results (configurable, default 1 hour)
- **LLM Cache** - LLM responses (configurable, default 1 hour)

### Query Optimization

- Index-aware filter ordering
- Efficient date range queries
- Optimized relationship joins
- Proper GROUP BY handling

## 🐛 Troubleshooting

### Common Issues

**Query not parsing correctly:**
- Check that models exist and are accessible
- Verify field names match database columns
- Enable LLM fallback for complex queries

**Slow queries:**
- Enable caching
- Check database indexes
- Reduce query complexity

**Permission errors:**
- Check security configuration
- Verify user authentication
- Review restricted models/fields

## 📚 Examples

### E-commerce Application

```php
// Find products
SemanticSearch::search("laptops under $1000 with 16GB RAM");

// Customer analytics
SemanticSearch::search("customers who purchased in last 30 days");

// Sales reports
SemanticSearch::search("total sales by category this month");
```

### Content Management

```php
// Find content
SemanticSearch::search("published articles about Laravel");

// User management
SemanticSearch::search("active users with admin role");

// Analytics
SemanticSearch::search("count of posts by author");
```

## 🤝 Contributing

Contributions are welcome! Please read our contributing guidelines and submit pull requests.

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## 🙏 Acknowledgments

- Laravel team for the excellent framework
- OpenAI for LLM API
- The Venmail team and early adopters

## 📞 Support

- **Documentation**: See [DEV.md](DEV.md) for development setup
- **Issues**: [GitHub Issues](https://github.com/venmail/laravel-semantic-search/issues)
- **Email**: support@venmail.com

---

**Made with ❤️ for the Laravel community**

