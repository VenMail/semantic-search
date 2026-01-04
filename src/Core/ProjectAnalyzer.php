<?php

namespace Venmail\SemanticSearch\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Venmail\SemanticSearch\Data\ProjectMetadata;

class ProjectAnalyzer
{
    private array $scanDirectories;
    private array $excludePatterns;
    
    public function __construct()
    {
        $this->scanDirectories = config('semantic-search.analysis.scan_directories', []);
        $this->excludePatterns = config('semantic-search.analysis.exclude_patterns', []);
    }
    
    public function analyze(): ProjectMetadata
    {
        $metadata = new ProjectMetadata();
        
        // Analyze controllers
        $controllers = $this->analyzeControllers($metadata);
        foreach ($controllers as $className => $data) {
            $metadata->addController($className, $data);
        }
        
        // Analyze models
        $models = $this->analyzeModels($metadata);
        foreach ($models as $className => $data) {
            $metadata->addModel($className, $data);
        }
        
        // Analyze views
        $views = $this->analyzeViews();
        foreach ($views as $viewName => $data) {
            $metadata->addView($viewName, $data);
        }
        
        // Analyze routes
        $routes = $this->analyzeRoutes();
        foreach ($routes as $route => $data) {
            $metadata->addRoute($route, $data);
        }
        
        // Extract relationships from models
        $relationships = $this->extractRelationships($metadata->getModels());
        foreach ($relationships as $rel) {
            $metadata->addRelationship($rel['from'], $rel['to'], $rel);
        }
        
        return $metadata;
    }
    
    private function analyzeControllers(ProjectMetadata $metadata): array
    {
        $controllers = [];
        $controllerDir = $this->scanDirectories['controllers'] ?? app_path('Http/Controllers');
        
        if (!is_dir($controllerDir)) {
            return $controllers;
        }
        
        $finder = (new Finder())
            ->files()
            ->in($controllerDir)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);
        
        foreach ($finder as $file) {
            $className = $this->getClassNameFromFile($file->getPathname());
            
            if (!$className || !class_exists($className)) {
                continue;
            }
            
            try {
                $reflection = new ReflectionClass($className);
                
                if (!$reflection->isInstantiable() || $reflection->isAbstract()) {
                    continue;
                }
                
                $controllers[$className] = [
                    'methods' => $this->extractMethods($reflection),
                    'patterns' => $this->extractPatterns($reflection),
                    'dependencies' => $this->extractDependencies($reflection),
                    'views' => $this->extractViewReferences($reflection),
                ];
                
                $metadata->addFile(
                    $file->getPathname(),
                    $file->getMTime(),
                    md5_file($file->getPathname())
                );
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        return $controllers;
    }
    
    private function analyzeModels(ProjectMetadata $metadata): array
    {
        $models = [];
        $modelDir = $this->scanDirectories['models'] ?? app_path('Models');
        
        if (!is_dir($modelDir)) {
            return $models;
        }
        
        $finder = (new Finder())
            ->files()
            ->in($modelDir)
            ->name('*.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);
        
        foreach ($finder as $file) {
            $className = $this->getClassNameFromFile($file->getPathname());
            
            if (!$className || !class_exists($className)) {
                continue;
            }
            
            try {
                $reflection = new ReflectionClass($className);
                
                if (!$reflection->isSubclassOf(Model::class)) {
                    continue;
                }
                
                $models[$className] = [
                    'relationships' => $this->extractModelRelationships($reflection),
                    'scopes' => $this->extractScopes($reflection),
                    'attributes' => $this->extractAttributes($reflection),
                    'computed' => $this->extractComputedAttributes($reflection),
                    'businessRules' => $this->extractBusinessRules($reflection),
                ];
                
                $metadata->addFile(
                    $file->getPathname(),
                    $file->getMTime(),
                    md5_file($file->getPathname())
                );
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        return $models;
    }
    
    private function analyzeViews(): array
    {
        $views = [];
        $viewDir = $this->scanDirectories['views'] ?? resource_path('views');
        
        if (!is_dir($viewDir)) {
            return $views;
        }
        
        $finder = (new Finder())
            ->files()
            ->in($viewDir)
            ->name('*.blade.php')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);
        
        foreach ($finder as $file) {
            $viewName = $this->getViewNameFromFile($file->getPathname());
            $content = file_get_contents($file->getPathname());
            
            $views[$viewName] = [
                'patterns' => $this->extractViewPatterns($content),
                'variables' => $this->extractViewVariables($content),
                'sections' => $this->extractViewSections($content),
                'components' => $this->extractViewComponents($content),
                'businessTerms' => $this->extractBusinessTerms($content),
            ];
        }
        
        return $views;
    }
    
    private function analyzeRoutes(): array
    {
        $routes = [];
        
        try {
            $routeCollection = app('router')->getRoutes();
            
            foreach ($routeCollection as $route) {
                $action = $route->getAction();
                $controller = $action['controller'] ?? null;
                
                if ($controller && is_string($controller)) {
                    $routes[$route->uri()] = [
                        'method' => implode('|', $route->methods()),
                        'controller' => $controller,
                        'name' => $route->getName(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Routes may not be available in all contexts
        }
        
        return $routes;
    }
    
    private function extractMethods(ReflectionClass $reflection): array
    {
        $methods = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            
            $methods[] = [
                'name' => $method->getName(),
                'parameters' => $this->extractMethodParameters($method),
                'returnType' => $method->getReturnType()?->getName(),
            ];
        }
        
        return $methods;
    }
    
    private function extractMethodParameters(ReflectionMethod $method): array
    {
        $parameters = [];
        
        foreach ($method->getParameters() as $param) {
            $parameters[] = [
                'name' => $param->getName(),
                'type' => $param->getType()?->getName(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }
        
        return $parameters;
    }
    
    private function extractPatterns(ReflectionClass $reflection): array
    {
        $patterns = [];
        $docComment = $reflection->getDocComment();
        
        if ($docComment) {
            // Extract common patterns from docblocks
            if (preg_match('/@pattern\s+(\w+)/', $docComment, $matches)) {
                $patterns[] = $matches[1];
            }
        }
        
        return $patterns;
    }
    
    private function extractDependencies(ReflectionClass $reflection): array
    {
        $dependencies = [];
        
        $constructor = $reflection->getConstructor();
        if ($constructor) {
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type && !$type->isBuiltin()) {
                    $dependencies[] = $type->getName();
                }
            }
        }
        
        return $dependencies;
    }
    
    private function extractViewReferences(ReflectionClass $reflection): array
    {
        $views = [];
        $filename = $reflection->getFileName();
        
        if ($filename && file_exists($filename)) {
            $content = file_get_contents($filename);
            
            // Look for view() calls
            preg_match_all("/view\(['\"]([^'\"]+)['\"]\)/", $content, $matches);
            $views = array_merge($views, $matches[1] ?? []);
            
            // Look for View::make() calls
            preg_match_all("/View::make\(['\"]([^'\"]+)['\"]\)/", $content, $matches);
            $views = array_merge($views, $matches[1] ?? []);
        }
        
        return array_unique($views);
    }
    
    private function extractModelRelationships(ReflectionClass $reflection): array
    {
        $relationships = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0) {
                continue;
            }
            
            $returnType = $method->getReturnType();
            $methodName = $method->getName();
            
            // Check if method returns a relation type
            $isRelation = false;
            $relationType = null;
            
            if ($returnType) {
                $returnTypeName = $returnType->getName();
                if (str_contains($returnTypeName, 'Relation') || 
                    str_contains($returnTypeName, 'HasMany') ||
                    str_contains($returnTypeName, 'BelongsTo') ||
                    str_contains($returnTypeName, 'HasOne') ||
                    str_contains($returnTypeName, 'BelongsToMany') ||
                    str_contains($returnTypeName, 'MorphMany') ||
                    str_contains($returnTypeName, 'MorphTo')) {
                    $isRelation = true;
                    
                    // Extract relation type from return type
                    if (str_contains($returnTypeName, 'HasMany')) {
                        $relationType = 'hasMany';
                    } elseif (str_contains($returnTypeName, 'BelongsTo')) {
                        $relationType = 'belongsTo';
                    } elseif (str_contains($returnTypeName, 'HasOne')) {
                        $relationType = 'hasOne';
                    } elseif (str_contains($returnTypeName, 'BelongsToMany')) {
                        $relationType = 'belongsToMany';
                    } elseif (str_contains($returnTypeName, 'MorphMany')) {
                        $relationType = 'morphMany';
                    } elseif (str_contains($returnTypeName, 'MorphTo')) {
                        $relationType = 'morphTo';
                    }
                }
            }
            
            // Also check method body for common relationship patterns
            if (!$isRelation) {
                $filename = $reflection->getFileName();
                if ($filename && file_exists($filename)) {
                    $content = file_get_contents($filename);
                    $methodPattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{[^}]*return\s+\$this->(hasMany|belongsTo|hasOne|belongsToMany|morphMany|morphTo)\(/s';
                    if (preg_match($methodPattern, $content, $matches)) {
                        $isRelation = true;
                        $relationType = $matches[1];
                    }
                }
            }
            
            if ($isRelation) {
                // Try to infer target model from method name or code
                $targetModel = $this->inferTargetModelFromMethod($method, $reflection);
                
                $relationships[$methodName] = [
                    'type' => $relationType ?? 'unknown',
                    'method' => $methodName,
                    'target' => $targetModel,
                ];
            }
        }
        
        return $relationships;
    }
    
    private function inferTargetModelFromMethod(ReflectionMethod $method, ReflectionClass $reflection): ?string
    {
        $methodName = $method->getName();
        $filename = $reflection->getFileName();
        
        if ($filename && file_exists($filename)) {
            $content = file_get_contents($filename);
            
            // Look for relationship method body
            $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*\{[^}]*return\s+\$this->(?:hasMany|belongsTo|hasOne|belongsToMany)\(([^,)]+)/s';
            if (preg_match($pattern, $content, $matches)) {
                $target = trim($matches[1], " \t\n\r\0\x0B'\"");
                
                // If it's a class reference, try to resolve it
                if (str_contains($target, '::class') || str_contains($target, '\\')) {
                    // Extract class name
                    if (preg_match('/([A-Za-z0-9_\\\\]+)::class/', $target, $classMatch)) {
                        return $classMatch[1];
                    }
                    if (preg_match('/([A-Za-z0-9_\\\\]+)/', $target, $classMatch)) {
                        $className = $classMatch[1];
                        // Try to resolve relative to current namespace
                        $currentNamespace = $reflection->getNamespaceName();
                        $fullClassName = $currentNamespace . '\\' . $className;
                        if (class_exists($fullClassName)) {
                            return $fullClassName;
                        }
                        if (class_exists($className)) {
                            return $className;
                        }
                    }
                }
            }
        }
        
        // Fallback: infer from method name (e.g., "posts" -> "Post")
        $singular = \Illuminate\Support\Str::singular($methodName);
        $modelName = \Illuminate\Support\Str::studly($singular);
        
        // Try common namespaces
        $namespaces = [
            $reflection->getNamespaceName(),
            'App\\Models',
            'App',
        ];
        
        foreach ($namespaces as $namespace) {
            $fullClassName = $namespace . '\\' . $modelName;
            if (class_exists($fullClassName)) {
                return $fullClassName;
            }
        }
        
        return null;
    }
    
    private function extractScopes(ReflectionClass $reflection): array
    {
        $scopes = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'scope')) {
                $scopeName = lcfirst(substr($method->getName(), 5));
                $scopes[$scopeName] = [
                    'name' => $scopeName,
                    'method' => $method->getName(),
                    'parameters' => $this->extractMethodParameters($method),
                ];
            }
        }
        
        return $scopes;
    }
    
    private function extractAttributes(ReflectionClass $reflection): array
    {
        $attributes = [];
        
        // Try to get fillable attributes
        if ($reflection->hasProperty('fillable')) {
            $fillable = $reflection->getProperty('fillable');
            $fillable->setAccessible(true);
            $fillableArray = $fillable->getValue($reflection->newInstanceWithoutConstructor());
            
            if (is_array($fillableArray)) {
                foreach ($fillableArray as $field) {
                    $attributes[$field] = 'fillable';
                }
            }
        }
        
        // Try to get casts
        if ($reflection->hasProperty('casts')) {
            $casts = $reflection->getProperty('casts');
            $casts->setAccessible(true);
            $castsArray = $casts->getValue($reflection->newInstanceWithoutConstructor());
            
            if (is_array($castsArray)) {
                foreach ($castsArray as $field => $type) {
                    $attributes[$field] = $type;
                }
            }
        }
        
        return $attributes;
    }
    
    private function extractComputedAttributes(ReflectionClass $reflection): array
    {
        $computed = [];
        
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'get') && 
                str_ends_with($method->getName(), 'Attribute') &&
                $method->getNumberOfParameters() === 1) {
                $attributeName = lcfirst(substr($method->getName(), 3, -9));
                $computed[$attributeName] = [
                    'name' => $attributeName,
                    'method' => $method->getName(),
                ];
            }
        }
        
        return $computed;
    }
    
    private function extractBusinessRules(ReflectionClass $reflection): array
    {
        $rules = [];
        $filename = $reflection->getFileName();
        
        if ($filename && file_exists($filename)) {
            $content = file_get_contents($filename);
            
            // Look for validation rules
            if (preg_match_all('/rules\s*=\s*\[(.*?)\]/s', $content, $matches)) {
                $rules['validation'] = $matches[1];
            }
        }
        
        return $rules;
    }
    
    private function extractViewPatterns(string $content): array
    {
        $patterns = [];
        
        // Extract common Blade patterns
        if (preg_match_all('/@(if|foreach|for|while|switch)\s*\(/', $content, $matches)) {
            $patterns = array_merge($patterns, $matches[1]);
        }
        
        return array_unique($patterns);
    }
    
    private function extractViewVariables(string $content): array
    {
        $variables = [];
        
        // Extract $variable patterns
        preg_match_all('/\$(\w+)/', $content, $matches);
        $variables = array_unique($matches[1] ?? []);
        
        return $variables;
    }
    
    private function extractViewSections(string $content): array
    {
        $sections = [];
        
        // Extract @section definitions
        preg_match_all("/@section\(['\"]([^'\"]+)['\"]\)/", $content, $matches);
        $sections = array_merge($sections, $matches[1] ?? []);
        
        return array_unique($sections);
    }
    
    private function extractViewComponents(string $content): array
    {
        $components = [];
        
        // Extract <x-component> usage
        preg_match_all('/<x-(\w+)/', $content, $matches);
        $components = array_merge($components, $matches[1] ?? []);
        
        // Extract @component usage
        preg_match_all("/@component\(['\"]([^'\"]+)['\"]\)/", $content, $matches);
        $components = array_merge($components, $matches[1] ?? []);
        
        return array_unique($components);
    }
    
    private function extractBusinessTerms(string $content): array
    {
        $terms = [];
        
        // Extract business terms from view content by looking for common patterns
        // This is generic and will adapt to any project's domain language
        
        // Look for capitalized terms (likely business entities)
        if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)\b/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $term = strtolower(trim($match));
                if (strlen($term) > 3 && !in_array($term, ['the', 'and', 'for', 'with', 'from', 'this', 'that'], true)) {
                    $terms[] = $term;
                }
            }
        }
        
        // Look for quoted terms (often business concepts)
        if (preg_match_all('/"([^"]+)"/', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $term = strtolower(trim($match));
                if (strlen($term) > 2) {
                    $terms[] = $term;
                }
            }
        }
        
        return array_unique($terms);
    }
    
    private function extractRelationships(array $models): array
    {
        $relationships = [];
        
        foreach ($models as $modelClass => $modelData) {
            foreach ($modelData['relationships'] ?? [] as $relName => $relData) {
                // This is a simplified extraction
                // Full implementation would analyze return types and infer target models
                $relationships[] = [
                    'from' => $modelClass,
                    'to' => 'unknown', // Would need deeper analysis
                    'type' => $relData['type'] ?? 'unknown',
                    'method' => $relName,
                ];
            }
        }
        
        return $relationships;
    }
    
    private function getClassNameFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        
        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }
        
        // Extract class name
        $className = null;
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
        }
        
        if ($namespace && $className) {
            return "{$namespace}\\{$className}";
        }
        
        return $className;
    }
    
    private function getViewNameFromFile(string $filePath): string
    {
        $viewPath = str_replace([resource_path('views'), '.blade.php', DIRECTORY_SEPARATOR], ['', '', '.'], $filePath);
        return trim($viewPath, '.');
    }
}

