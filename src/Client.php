<?php

namespace Rancher;

use Rancher\Resource\Collection;
use Rancher\Resource\Resource;
use Rancher\Resource\Action;
use Rancher\Exception\InvalidResourceException;
use Rancher\Exception\InvalidResourceTypeException;
use Rancher\Exception\ResourceNotFoundException;
use Rancher\Exception\GetResourceException;

/**
 * Class Client
 * @package Rancher
 */
class Client
{
    /**
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * @var string
     */
    private $host;

    /**
     * @var string
     */
    private $authKey;

    /**
     * @var string
     */
    private $authSecret;

    /**
     * @var array<\Rancher\Resource\Resource>
     */
    private $resources;

    /**
     * @param $host string
     * @param $authKey string
     * @param $authSecret string
     */
    public function __construct($host, $authKey, $authSecret)
    {
        $this->host = $host;
        $this->authKey = $authKey;
        $this->authSecret = $authSecret;
    }

    /**
     * @param $method string
     * @param $args mixed
     * @return \Rancher\Resource\Collection
     */
    public function __call($method, $args)
    {
        // discover links first
        $this->discover();

        $resourceName = strtolower(str_replace('get', '', $method));
        if (false == array_key_exists($resourceName, $this->resources)) {
            throw new InvalidResourceException(sprintf('Resource "%s" not found in context', $resourceName));
        }

        $resource = $this->resources[$resourceName];
        return $this->getResource($resource);
    }

    /**
     * Load the initial links from the API
     */
    public function discover()
    {
        if (null === $this->resources) {
            $response = $this->call($this->host);

            $json = json_decode($response->getBody(), true);

            foreach ($json['links'] as $resourceName => $uri) {
                $resource = new Resource($this, $uri);

                $this->resources[strtolower($resourceName)] = $resource;
            }
        }
    }

    public function doAction(Resource $resource, Action $action, $args)
    {
        try {
            $response = $this->call($resource->getUri(), 'post', [
                'body' => json_encode($args),
                'query' => [
                    'action' => $action->getName(),
                ],
            ]);

            $json = json_decode($response->getBody(), true);

            return $json;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() == 404) {
                throw new ResourceNotFoundException($e->getMessage(), 404);
            } else {
                throw new GetResourceException($e->getMessage(), $e->getCode(), $e->getResponse());
            }
        }
    }

    /**
     * @param \Rancher\Resource\Resource $resource
     * @return \Rancher\Resource\Collection
     */
    public function getResource(Resource $resource)
    {
        try {
            $response = $this->call($resource->getUri());

            $json = json_decode($response->getBody(), true);

            $class = sprintf("Rancher\\Resource\\Type\\%s", ucfirst($json['resourceType']));

            if (!class_exists($class)) {
                throw new InvalidResourceTypeException(sprintf('Resource type "%s" not found', $json['resourceType']));
            }

            $collection = new Collection();

            foreach ($json['data'] as $data) {
                $item = new $class($this, $data['links']['self']);
                $r = new \ReflectionObject($item);

                foreach ($data as $key => $value) {
                    if ($r->hasProperty($key)) {
                        // special hack case for created date
                        if ($key == "created") {
                            $value = new \DateTime($value);
                        }

                        $property = $r->getProperty($key);
                        $property->setAccessible(true);
                        $property->setValue($item, $value);
                    }

                    foreach ($data['links'] as $resourceName => $uri) {
                        $resource = new Resource($this, $uri);
                        $item->setResource(strtolower($resourceName), $resource);
                    }

                    foreach ($data['actions'] as $actionName => $uri) {
                        $query = parse_url($uri, PHP_URL_QUERY);
                        parse_str($query, $parts);

                        if (isset($parts['action'])) {
                            $action = new Action($parts['action']);
                            $item->setAction(strtolower($actionName), $action);
                        }
                    }
                }

                $collection->set($data['id'], $item);
            }

            return $collection;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() == 404) {
                throw new ResourceNotFoundException($e->getMessage(), 404);
            } else {
                throw new GetResourceException($e->getMessage(), $e->getCode(), $e->getResponse());
            }
        }
    }

    /**
     * @param $uri string
     * @param string $method
     * @return Response
     */
    private function call($uri, $method = 'get', array $args = [])
    {
        $params = array_merge_recursive([
            'auth' => [
                $this->authKey, $this->authSecret,
            ],
        ], $args);

        return $this->getClient()->$method($uri, $params);
    }

    /**
     * @return \GuzzleHttp\Client
     */
    private function getClient()
    {
        if (null == $this->client) {
            $this->client = new \GuzzleHttp\Client();
        }

        return $this->client;
    }
}