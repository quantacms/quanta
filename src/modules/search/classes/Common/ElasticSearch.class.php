<?php

namespace Quanta\Common;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticSearch
{
    private Environment $env;
    private Client $client;
    private string $index;

    public function __construct(Environment $env)
    {
        $this->env = $env;
        $host = $env->getData('ELASTICSEARCH_HOST', 'http://127.0.0.1:9200');
        $this->index = $env->getData('ELASTICSEARCH_INDEX', 'quanta-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($env->host)));
        $this->client = ClientBuilder::create()->setHosts(array($host))->build();
    }

    public function getIndex(): string
    {
        return $this->index;
    }

    public function ensureIndex(): void
    {
        if ($this->client->indices()->exists(array('index' => $this->index))->asBool()) {
            return;
        }
        $this->client->indices()->create(array('index' => $this->index));
    }

    public function indexAll(): int
    {
        $this->ensureIndex();
        $names = $this->env->db()->find(array('name_prefix' => ''), array('return' => 'names'));
        $count = 0;
        foreach ($names as $name) {
            $node = NodeFactory::load($this->env, $name, null, true);
            if (!$node->exists || $node->forbidden) {
                continue;
            }
            $this->client->index(array(
            'index' => $this->index,
            'id' => $name,
            'body' => $this->document($node),
            ));
            $count++;
        }
        $this->client->indices()->refresh(array('index' => $this->index));
        return $count;
    }

    public function search(string $query = '', array $facets = array(), int $size = 20): array
    {
        $must = $query === ''
        ? array(array('match_all' => new \stdClass()))
        : array(array('multi_match' => array('query' => $query, 'fields' => array('title^3', 'teaser^2', 'body', 'fields.*'))));
        $filter = array();
        foreach ($facets as $field => $value) {
            if ($field !== '' && $value !== '') {
                $filter[] = array('term' => array('fields.' . $field . '.keyword' => $value));
            }
        }
        $body = array('query' => array('bool' => array('must' => $must, 'filter' => $filter)), 'size' => $size);
        return $this->client->search(array('index' => $this->index, 'body' => $body))->asArray();
    }

    public function facet(string $field, string $query = '', array $selected = array(), int $size = 25): array
    {
        $must = $query === ''
            ? array(array('match_all' => new \stdClass()))
            : array(array('multi_match' => array(
                'query' => $query,
                'fields' => array('title^3', 'teaser^2', 'body', 'fields.*'),
            )));
        $filter = array();
        foreach ($selected as $key => $value) {
            if ($key !== $field && $key !== '' && $value !== '') {
                $filter[] = array('term' => array('fields.' . $key . '.keyword' => $value));
            }
        }
        $body = array(
        'query' => array('bool' => array('must' => $must, 'filter' => $filter)),
        'size' => 0,
        'aggs' => array('facet' => array('terms' => array('field' => 'fields.' . $field . '.keyword', 'size' => $size))),
        );
        $result = $this->client->search(array('index' => $this->index, 'body' => $body))->asArray();
        return $result['aggregations']['facet']['buckets'] ?? array();
    }

    private function document(Node $node): array
    {
        $fields = json_decode(json_encode($node->json), true) ?: array();
        unset($fields['permissions']);
        return array(
        'name' => $node->getName(),
        'title' => (string) $node->getTitle(),
        'teaser' => strip_tags((string) $node->getTeaser()),
        'body' => strip_tags((string) $node->getBody()),
        'status' => (string) $node->getStatus(),
        'timestamp' => $node->getTimestamp(),
        'fields' => $fields,
        );
    }
}
