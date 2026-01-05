# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [0.3.0] - 2026-01-05
### ⚠️ BREAKING CHANGES
- Replaced `SearchEngine` with `EnhancedSemanticSearchService` as the main entry point
- Removed `QueryParser` wrapper class
- Updated service provider to register enhanced components
- Updated facade methods to include new enhanced features

### Added
- **EnhancedSemanticSearchService**: New unified search service with intelligent caching, metrics, and performance optimization
- **QueryEnhancer**: Intelligence component for automatic query enhancement (entity detection, temporal context, business context, user intent)
- **ActionDetector**: Automatic detection of missing action words (e.g., "mails 3 days old" → adds "list" action)
- **SemanticFieldPatterns**: Centralized configuration hub for project-agnostic field patterns, relationships, and mappings
- **Performance Metrics**: Built-in metrics tracking (queries processed, execution time, cache hits, fallback usage, error count)
- **Intelligent Caching**: Automatic cache key generation and hit detection
- **Query Explanation**: Detailed query analysis with enhancement tracking and performance estimation
- **Timeout Protection**: Query execution timeout to prevent long-running queries
- **Enhanced Error Handling**: Three-tier fallback system with intelligent suggestions
- **Project-Agnostic Patterns**: Generic field and relationship patterns that work with any Laravel project

### Enhanced
- **Query Processing**: 8-step enhancement pipeline with intelligent processing based on confidence
- **Relationship Interpretation**: More sophisticated relationship mapping and inference
- **Fallback Strategy**: Aggressive mapping fallback for low-confidence short queries
- **SQL Validation**: Part-by-part structural validation and fixing
- **API Methods**: New methods `explainQuery()`, `getMetrics()`, `resetMetrics()`, `generateSuggestions()`

### Removed
- **Redundant Components**: `SearchEngine`, `RobustSemanticSearchService`, `QueryParser`
- **Outdated Tests**: Legacy test files replaced with comprehensive enhanced test suite
- **Hardcoded Patterns**: Moved all hardcoded patterns to centralized configuration

### Fixed
- **Test Suite**: 26/26 tests passing with comprehensive coverage
- **Code Duplication**: Eliminated all redundant code and consolidated functionality
- **Project Dependencies**: Removed hardcoded `App\Models\` references
- **Service Provider**: Updated to register enhanced components correctly

### Performance
- **Caching**: Intelligent caching reduces database queries
- **Optimization**: Full-text search support and optimized query building
- **Memory**: Efficient data structures and lazy loading
- **Execution**: Timeout protection and performance monitoring

## [0.2.4] - 2026-01-05
### Added
- Improved virtual attribute (accessor) detection with support for ternary operators and quoted string literals in method bodies.
- Robust handling of array values in filters to prevent "Array to string conversion" errors.
- Enhanced FieldResolver to ignore common non-column model properties during analysis.

## [0.2.3] - 2026-01-05
### Added
- Automatic accessor/virtual field detection in the semantic query builder so natural-language filters map to the correct physical columns without manual overrides.
- Configurable `field_overrides` section to let applications explicitly map edge-case attributes when needed.

### Fixed
- Guarded against empty-string sentinel filters by translating them into proper NULL/NOT NULL predicates, preventing malformed SQL and incorrect fallbacks.

## [0.2.2] - 2025-12-??
### Added
- Initial public release with static analysis pipeline, multilingual parser, and optional LLM fallback.
