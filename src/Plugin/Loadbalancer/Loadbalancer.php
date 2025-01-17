<?php

/*
 * This file is part of the Solarium package.
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code.
 */

namespace Solarium\Plugin\Loadbalancer;

use Solarium\Core\Client\Client;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Event\Events;
use Solarium\Core\Event\PreCreateRequest;
use Solarium\Core\Event\PreExecuteRequest;
use Solarium\Core\Plugin\AbstractPlugin;
use Solarium\Exception\HttpException;
use Solarium\Exception\InvalidArgumentException;
use Solarium\Exception\OutOfBoundsException;
use Solarium\Exception\RuntimeException;
use Solarium\Plugin\Loadbalancer\Event\EndpointFailure as EndpointFailureEvent;

/**
 * Loadbalancer plugin.
 *
 * Using this plugin you can use software loadbalancing over multiple Solr instances.
 * You can add any number of endpoints, each with their own weight. The weight influences
 * the probability of a endpoint being used for a query.
 *
 * By default all queries except updates are loadbalanced. This can be customized by setting blocked querytypes.
 * Any querytype that may not be loadbalanced will be executed by Solarium with the default endpoint.
 * In a master-slave setup the default endpoint should be connecting to the master endpoint.
 *
 * You can also enable the failover mode. In this case a query will be retried on another endpoint in case of error.
 */
class Loadbalancer extends AbstractPlugin
{
    /**
     * Default options.
     *
     * @var array
     */
    protected $options = [
        'failoverenabled' => false,
        'failovermaxretries' => 1,
    ];

    /**
     * Registered endpoints.
     *
     * @var Endpoint[]
     */
    protected $endpoints = [];

    /**
     * Query types that are blocked from loadbalancing.
     *
     * @var array
     */
    protected $blockedQueryTypes = [
        Client::QUERY_UPDATE => true,
    ];

    /**
     * Last used endpoint key.
     *
     * The value can be null if no queries have been executed, or if the last executed query didn't use loadbalancing.
     *
     * @var string|null
     */
    protected $lastEndpoint;

    /**
     * Endpoint key to use for next query (overrules randomizer).
     *
     * @var string
     */
    protected $nextEndpoint;

    /**
     * Default endpoint key.
     *
     * This endpoint is used for queries that cannot be loadbalanced
     * (for instance update queries that need to go to the master)
     *
     * @var string
     */
    protected $defaultEndpoint;

    /**
     * Pool of endpoint keys to use for requests.
     *
     * @var WeightedRandomChoice
     */
    protected $randomizer;

    /**
     * Query type.
     *
     * @var string
     */
    protected $queryType;

    /**
     * Used for failover mechanism.
     *
     * @var array
     */
    protected $endpointExcludes;

    /**
     * Set failover enabled option.
     *
     * @param bool $value
     *
     * @return self Provides fluent interface
     */
    public function setFailoverEnabled(bool $value): self
    {
        $this->setOption('failoverenabled', $value);

        return $this;
    }

    /**
     * Get failoverenabled option.
     *
     * @return bool|null
     */
    public function getFailoverEnabled(): ?bool
    {
        return $this->getOption('failoverenabled');
    }

    /**
     * Set failover max retries.
     *
     * @param int $value
     *
     * @return self Provides fluent interface
     */
    public function setFailoverMaxRetries(int $value): self
    {
        $this->setOption('failovermaxretries', $value);

        return $this;
    }

    /**
     * Get failovermaxretries option.
     *
     * @return int|null
     */
    public function getFailoverMaxRetries(): ?int
    {
        return $this->getOption('failovermaxretries');
    }

    /**
     * Add an endpoint to the loadbalacing 'pool'.
     *
     * @param Endpoint|string $endpoint
     * @param int             $weight   Must be a positive number
     *
     * @throws InvalidArgumentException
     *
     * @return self Provides fluent interface
     */
    public function addEndpoint($endpoint, int $weight = 1): self
    {
        if (!\is_string($endpoint)) {
            $endpoint = $endpoint->getKey();
        }

        if (\array_key_exists($endpoint, $this->endpoints)) {
            throw new InvalidArgumentException('An endpoint for the loadbalancer plugin must have a unique key');
        }

        $this->endpoints[$endpoint] = $weight;

        // reset the randomizer as soon as a new endpoint is added
        $this->randomizer = null;

        return $this;
    }

    /**
     * Add multiple endpoints.
     *
     * @param array $endpoints
     *
     * @return self Provides fluent interface
     */
    public function addEndpoints(array $endpoints): self
    {
        foreach ($endpoints as $endpoint => $weight) {
            $this->addEndpoint($endpoint, $weight);
        }

        return $this;
    }

    /**
     * Get the endpoints in the loadbalancing pool.
     *
     * @return Endpoint[]
     */
    public function getEndpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * Clear all endpoint entries.
     *
     * @return self Provides fluent interface
     */
    public function clearEndpoints(): self
    {
        $this->endpoints = [];

        return $this;
    }

    /**
     * Remove an endpoint by key.
     *
     * @param Endpoint|string $endpoint
     *
     * @return self Provides fluent interface
     */
    public function removeEndpoint($endpoint): self
    {
        if (!\is_string($endpoint)) {
            $endpoint = $endpoint->getKey();
        }

        if (isset($this->endpoints[$endpoint])) {
            unset($this->endpoints[$endpoint]);
        }

        return $this;
    }

    /**
     * Set multiple endpoints.
     *
     * This overwrites any existing endpoints
     *
     * @param array $endpoints
     *
     * @return self Provides fluent interface
     */
    public function setEndpoints($endpoints): self
    {
        $this->clearEndpoints();
        $this->addEndpoints($endpoints);

        return $this;
    }

    /**
     * Set a forced endpoints (by key) for the next request.
     *
     * As soon as one query has used the forced endpoint this setting is reset. If you want to remove this setting
     * pass NULL as the key value.
     *
     * If the next query cannot be loadbalanced (for instance based on the querytype) this setting is ignored
     * but will still be reset.
     *
     * @param string|Endpoint|null $endpoint
     *
     * @throws OutOfBoundsException
     *
     * @return self Provides fluent interface
     */
    public function setForcedEndpointForNextQuery($endpoint): self
    {
        if (!\is_string($endpoint)) {
            $endpoint = $endpoint->getKey();
        }

        if (null !== $endpoint && !\array_key_exists($endpoint, $this->endpoints)) {
            throw new OutOfBoundsException('Unknown endpoint forced for next query');
        }

        $this->nextEndpoint = $endpoint;

        return $this;
    }

    /**
     * Get the ForcedEndpointForNextQuery value.
     *
     * @return string|null
     */
    public function getForcedEndpointForNextQuery(): ?string
    {
        return $this->nextEndpoint;
    }

    /**
     * Get an array of blocked querytypes.
     *
     * @return array
     */
    public function getBlockedQueryTypes(): array
    {
        return array_keys($this->blockedQueryTypes);
    }

    /**
     * Set querytypes to block from loadbalancing.
     *
     * Overwrites any existing types
     *
     * @param array $types Use an array with the constants defined in Solarium\Client as values
     *
     * @return self Provides fluent interface
     */
    public function setBlockedQueryTypes(array $types): self
    {
        $this->clearBlockedQueryTypes();
        $this->addBlockedQueryTypes($types);

        return $this;
    }

    /**
     * Add a querytype to block from loadbalancing.
     *
     * @param string $type Use one of the constants defined in Solarium\Client
     *
     * @return self Provides fluent interface
     */
    public function addBlockedQueryType(string $type): self
    {
        if (!\array_key_exists($type, $this->blockedQueryTypes)) {
            $this->blockedQueryTypes[$type] = true;
        }

        return $this;
    }

    /**
     * Add querytypes to block from loadbalancing.
     *
     * Appended to any existing types
     *
     * @param array $types Use an array with the constants defined in Solarium\Client as values
     *
     * @return self Provides fluent interface
     */
    public function addBlockedQueryTypes(array $types): self
    {
        foreach ($types as $type) {
            $this->addBlockedQueryType($type);
        }

        return $this;
    }

    /**
     * Remove a single querytype from the block list.
     *
     * @param string $type
     *
     * @return self Provides fluent interface
     */
    public function removeBlockedQueryType(string $type): self
    {
        if (\array_key_exists($type, $this->blockedQueryTypes)) {
            unset($this->blockedQueryTypes[$type]);
        }

        return $this;
    }

    /**
     * Clear all blocked querytypes.
     *
     * @return self Provides fluent interface
     */
    public function clearBlockedQueryTypes(): self
    {
        $this->blockedQueryTypes = [];

        return $this;
    }

    /**
     * Get the key of the endpoint that was used for the last query.
     *
     * May return a null value if no query has been executed yet, or the last query could not be loadbalanced.
     *
     * @return string|null
     */
    public function getLastEndpoint(): ?string
    {
        return $this->lastEndpoint;
    }

    /**
     * Event hook to capture querytype.
     *
     * @param object $event
     *
     * @return self Provides fluent interface
     */
    public function preCreateRequest($event): self
    {
        // We need to accept event proxies or decorators.
        /* @var PreCreateRequest $event */
        $this->queryType = $event->getQuery()->getType();

        return $this;
    }

    /**
     * Event hook to adjust client settings just before query execution.
     *
     * @param object $event
     *
     * @return self Provides fluent interface
     */
    public function preExecuteRequest($event): self
    {
        // We need to accept event proxies or decorators.
        /* @var PreExecuteRequest $event */
        $adapter = $this->client->getAdapter();

        // save adapter presets (once) to allow the settings to be restored later
        if (null === $this->defaultEndpoint) {
            $this->defaultEndpoint = $this->client->getEndpoint()->getKey();
        }

        // check querytype: is loadbalancing allowed?
        if (!\array_key_exists($this->queryType, $this->blockedQueryTypes)) {
            $response = $this->getLoadbalancedResponse($event->getRequest());
        } else {
            $endpoint = $this->client->getEndpoint($this->defaultEndpoint);
            $this->lastEndpoint = null;

            // execute request and return result
            $response = $adapter->execute($event->getRequest(), $endpoint);
        }

        $event->setResponse($response);

        return $this;
    }

    /**
     * Execute a request using the adapter.
     *
     * @param Request $request
     *
     * @throws RuntimeException
     *
     * @return Response $response
     */
    protected function getLoadbalancedResponse(Request $request): Response
    {
        $this->endpointExcludes = []; // reset for each query
        $adapter = $this->client->getAdapter();

        if (true === $this->getFailoverEnabled()) {
            $maxRetries = $this->getFailoverMaxRetries();
            for ($i = 0; $i <= $maxRetries; ++$i) {
                $endpoint = $this->getRandomEndpoint();
                try {
                    return $adapter->execute($request, $endpoint);
                } catch (HttpException $e) {
                    // ignore HTTP errors and try again
                    // but do issue an event for things like logging
                    $event = new EndpointFailureEvent($endpoint, $e);
                    $this->client->getEventDispatcher()->dispatch($event);
                }
            }

            // if we get here no more retries available, throw exception
            throw new RuntimeException('Maximum number of loadbalancer retries reached');
        }

        // no failover retries, just execute and let an exception bubble upwards
        $endpoint = $this->getRandomEndpoint();

        return $adapter->execute($request, $endpoint);
    }

    /**
     * Get a random endpoint.
     *
     * @return Endpoint
     */
    protected function getRandomEndpoint(): Endpoint
    {
        // determine the endpoint to use
        if (null !== $this->nextEndpoint) {
            $key = $this->nextEndpoint;
            // reset forced endpoint directly after use
            $this->nextEndpoint = null;
        } else {
            $key = $this->getRandomizer()->getRandom($this->endpointExcludes);
        }

        $this->endpointExcludes[] = $key;
        $this->lastEndpoint = $key;

        return $this->client->getEndpoint($key);
    }

    /**
     * Get randomizer instance.
     *
     * @return WeightedRandomChoice
     */
    protected function getRandomizer(): WeightedRandomChoice
    {
        if (null === $this->randomizer) {
            $this->randomizer = new WeightedRandomChoice($this->endpoints);
        }

        return $this->randomizer;
    }

    /**
     * Initialize options.
     *
     * {@internal Several options need some extra checks or setup work,
     *            for these options the setters are called.}
     */
    protected function init()
    {
        foreach ($this->options as $name => $value) {
            switch ($name) {
                case 'endpoint':
                    $this->setEndpoints($value);
                    break;
                case 'blockedquerytype':
                    $this->setBlockedQueryTypes($value);
                    break;
            }
        }
    }

    /**
     * Plugin init function.
     *
     * Register event listeners.
     */
    protected function initPluginType()
    {
        $dispatcher = $this->client->getEventDispatcher();
        if (is_subclass_of($dispatcher, '\Symfony\Component\EventDispatcher\EventDispatcherInterface')) {
            // The Loadbalancer plugin needs to be the last plugin executed on PRE_EXECUTE_REQUEST. Set Priority to 0.
            $dispatcher->addListener(Events::PRE_EXECUTE_REQUEST, [$this, 'preExecuteRequest'], 0);
            $dispatcher->addListener(Events::PRE_CREATE_REQUEST, [$this, 'preCreateRequest']);
        }
    }

    /**
     * Plugin cleanup function.
     *
     * Unregister event listeners.
     */
    public function deinitPlugin()
    {
        $dispatcher = $this->client->getEventDispatcher();
        if (is_subclass_of($dispatcher, '\Symfony\Component\EventDispatcher\EventDispatcherInterface')) {
            $dispatcher->removeListener(Events::PRE_EXECUTE_REQUEST, [$this, 'preExecuteRequest']);
            $dispatcher->removeListener(Events::PRE_CREATE_REQUEST, [$this, 'preCreateRequest']);
        }
    }
}
