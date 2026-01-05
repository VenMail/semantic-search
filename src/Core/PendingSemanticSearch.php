<?php

declare(strict_types=1);

namespace Venmail\SemanticSearch\Core;

use Venmail\SemanticSearch\Data\SearchResult;

class PendingSemanticSearch
{
    /**
     * @var SearchEngine
     */
    private $engine;

    /**
     * @var string
     */
    private $query;

    /**
     * @var array<string, mixed>
     */
    private $options = [];

    public function __construct(SearchEngine $engine, string $query)
    {
        $this->engine = $engine;
        $this->query = $query;
    }

    public function fromTables(array $tables): self
    {
        $normalized = array_values(array_filter(
            $tables,
            static fn ($table) => is_string($table) && trim($table) !== ''
        ));

        $this->options['tables'] = $normalized;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->options['limit'] = max(1, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->options['offset'] = max(0, $offset);

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->options['locale'] = $locale;

        return $this;
    }

    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    public function get(): SearchResult
    {
        return $this->engine->search($this->query, $this->options);
    }
}
