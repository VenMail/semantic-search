<?php

namespace Venmail\SemanticSearch\Data;

class ProjectMetadata
{
    private array $controllers = [];
    private array $models = [];
    private array $views = [];
    private array $routes = [];
    private array $relationships = [];
    private array $businessLogic = [];
    private array $fileHashes = [];
    
    public function __construct(
        array $controllers = [],
        array $models = [],
        array $views = [],
        array $routes = [],
        array $relationships = [],
        array $businessLogic = []
    ) {
        $this->controllers = $controllers;
        $this->models = $models;
        $this->views = $views;
        $this->routes = $routes;
        $this->relationships = $relationships;
        $this->businessLogic = $businessLogic;
    }
    
    public function getControllers(): array
    {
        return $this->controllers;
    }
    
    public function getModels(): array
    {
        return $this->models;
    }
    
    public function getViews(): array
    {
        return $this->views;
    }
    
    public function getRoutes(): array
    {
        return $this->routes;
    }
    
    public function getRelationships(): array
    {
        return $this->relationships;
    }
    
    public function getBusinessLogic(): array
    {
        return $this->businessLogic;
    }
    
    public function addController(string $className, array $data): void
    {
        $this->controllers[$className] = $data;
    }
    
    public function addModel(string $className, array $data): void
    {
        $this->models[$className] = $data;
    }
    
    public function addView(string $viewName, array $data): void
    {
        $this->views[$viewName] = $data;
    }
    
    public function addRoute(string $route, array $data): void
    {
        $this->routes[$route] = $data;
    }
    
    public function addRelationship(string $from, string $to, array $data): void
    {
        $key = "{$from}:{$to}";
        $this->relationships[$key] = $data;
    }
    
    public function addBusinessLogic(string $key, array $data): void
    {
        $this->businessLogic[$key] = $data;
    }
    
    public function addFile(string $path, int $mtime, string $hash): void
    {
        $this->fileHashes[$path] = [
            'mtime' => $mtime,
            'hash' => $hash,
        ];
    }
    
    public function getFileHashes(): array
    {
        return $this->fileHashes;
    }
    
    public function hasChangedFiles(array $currentHashes): bool
    {
        foreach ($currentHashes as $path => $data) {
            if (!isset($this->fileHashes[$path])) {
                return true;
            }
            
            if ($this->fileHashes[$path]['mtime'] !== $data['mtime'] ||
                $this->fileHashes[$path]['hash'] !== $data['hash']) {
                return true;
            }
        }
        
        return false;
    }
    
    public function toArray(): array
    {
        return [
            'controllers' => $this->controllers,
            'models' => $this->models,
            'views' => $this->views,
            'routes' => $this->routes,
            'relationships' => $this->relationships,
            'businessLogic' => $this->businessLogic,
            'fileHashes' => $this->fileHashes,
        ];
    }
}



