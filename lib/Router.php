<?php

namespace Aerys;

use FastRoute\{
    Dispatcher,
    RouteCollector,
    function simpleDispatcher
};

use Amp\{
    Promise,
    Success,
    Failure,
    function any
};

class Router implements Bootable, Middleware, ServerObserver {
    const CANONICAL_REDIRECT = 0;

    private $state = Server::STOPPED;
    private $bootLoader;
    private $routeDispatcher;
    private $routes = [];
    private $actions = [];
    private $cache = [];
    private $cacheEntryCount = 0;
    private $maxCacheEntries = 512;

    /**
     * Set a router option
     *
     * @param string $key
     * @param mixed $value
     * @throws \DomainException on unknown option key
     * @retur void
     */
    public function setOption(string $key, $value) {
        switch ($key) {
            case "max_cache_entries":
                if (!is_int($value)) {
                    throw new \TypeError(sprintf(
                        "max_cache_entries requires an integer; %s specified",
                        is_object($value) ? get_class($value) : gettype($value)
                    ));
                }
                $this->maxCacheEntries = ($value < 1) ? 0 : $value;
                break;
            default:
                throw new \DomainException(
                    "Unknown Router option: {$key}"
                );
        }
    }

    /**
     * Route a request
     *
     * @param \Aerys\Request $request
     * @param \Aerys\Response $response
     * @return mixed
     */
    public function __invoke(Request $request, Response $response) {
        if (!$preRoute = $request->getLocalVar("aerys.routed")) {
            return;
        }

        list($isMethodAllowed, $data) = $preRoute;
        if ($isMethodAllowed) {
            return $data($request, $response, $request->getLocalVar("aerys.routeArgs"));
        } else {
            $allowedMethods = implode(",", $data);
            $response->setStatus(HTTP_STATUS["METHOD_NOT_ALLOWED"]);
            $response->setHeader("Allow", $allowedMethods);
            $response->setHeader("Aerys-Generic-Response", "enable");
            $response->end();
        }
    }

    /**
     * Execute router middleware functionality
     */
    public function do(InternalRequest $ireq) {
        $toMatch = $ireq->uriPath;

        if (isset($this->cache[$toMatch])) {
            list($args, $routeArgs) = $cache = $this->cache[$toMatch];
            list($action, $middlewares) = $args;
            $ireq->locals["aerys.routeArgs"] = $routeArgs;
            // Keep the most recently used entry at the back of the LRU cache
            unset($this->cache[$toMatch]);
            $this->cache[$toMatch] = $cache;

            $ireq->locals["aerys.routed"] = [$isMethodAllowed = true, $action];
            if (!empty($middlewares)) {
                yield from responseFilter($middlewares, $ireq);
            }
        }

        $match = $this->routeDispatcher->dispatch($ireq->method, $toMatch);

        switch ($match[0]) {
            case Dispatcher::FOUND:
                list(, $args, $routeArgs) = $match;
                list($action, $middlewares) = $args;
                $ireq->locals["aerys.routeArgs"] = $routeArgs;
                if ($this->maxCacheEntries > 0) {
                    $this->cacheDispatchResult($toMatch, $routeArgs, $args);
                }

                $ireq->locals["aerys.routed"] = [$isMethodAllowed = true, $action];
                if (!empty($middlewares)) {
                    yield from responseFilter($middlewares, $ireq);
                }
                break;
            case Dispatcher::NOT_FOUND:
                // Do nothing; allow actions further down the chain a chance to respond.
                // If no other registered host actions respond the server will send a
                // 404 automatically anyway.
                return;
                break;
            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowedMethods = $match[1];
                $ireq->locals["aerys.routed"] = [$isMethodAllowed = false, $allowedMethods];
                break;
            default:
                throw new \UnexpectedValueException(
                    "Encountered unexpected Dispatcher code"
                );
        }
    }

    /**
     * Import a router or attach a callable, Middleware or Bootable.
     * Router imports do *not* import the options
     *
     * @param callable|\Aerys\Middleware|\Aerys\Bootable $action
     * @return self
     */
    public function use($action) {
        if (is_callable($action) || $action instanceof Middleware || $action instanceof Bootable) {
            throw new \InvalidArgumentException(
                __METHOD__ . " requires a callable action or Middleware instance"
            );
        }

        /* never overload the canonicalRedirector */
        if ($action instanceof self) {
            /* merge routes in for better performance */
            foreach ($action->routes as $route) {
                if ($route[2] !== self::CANONICAL_REDIRECT) {
                    $route[2] = array_merge($this->actions, $route[2]);
                }
                $this->routes[] = $route;
            }
        } else {
            $this->actions[] = $action;
            foreach ($this->routes as &$route) {
                if ($route[2] !== self::CANONICAL_REDIRECT) {
                    $route[2][] = $action;
                }
            }
        }

        return $this;
    }

    /**
     * Prefix all the routes with a given prefix
     *
     * @param string $prefix
     * @return self
     */
    public function prefix(string $prefix) {
        $prefix = trim($prefix, "/");

        if ($prefix != "") {
            foreach ($this->routes as &$route) {
                $route[1] = "/$prefix$route[1]";
            }
        }

        return $this;
    }

    private function cacheDispatchResult(string $toMatch, array $routeArgs, array $action) {
        if ($this->cacheEntryCount < $this->maxCacheEntries) {
            $this->cacheEntryCount++;
        } else {
            // Remove the oldest entry from the LRU cache to make room
            $unsetMe = key($this->cache);
            unset($this->cache[$unsetMe]);
        }

        $cacheKey = $toMatch;
        $this->cache[$cacheKey] = [$action, $routeArgs];
    }

    /**
     * Allow shortcut route registration using the called method name as the HTTP method verb
     *
     * HTTP method verbs -- though case-sensitive -- are used in all-caps for most applications.
     * Shortcut method verbs will automatically be changed to all-caps. Applications wishing to
     * define case-sensitive methods should use Router::route() to specify the desired method
     * directly.
     *
     * @param string $method
     * @param string $uri
     * @param callable|\Aerys\Middleware|\Aerys\Bootable $actions
     * @return self
     */
    public function __call(string $method, array $args): Router {
        $uri = $args ? array_shift($args) : "";

        return $this->route(strtoupper($method), $uri, ...$args);
    }

    /**
     * Define an application route
     *
     * The variadic ...$actions argument allows applications to specify multiple separate
     * handlers for a given route URI. When matched these action callables will be invoked
     * in order until one starts a response. If the resulting action fails to send a response
     * the end result is a 404.
     *
     * Matched URI route arguments are made available to action callables as an array in the
     * following Request property:
     *
     *     $request->locals->routeArgs array.
     *
     * Route URIs ending in "/?" (without the quotes) allow a URI match with or without
     * the trailing slash. Temporary redirects are used to redirect to the canonical URI
     * (with a trailing slash) to avoid search engine duplicate content penalties.
     *
     * @param string $method The HTTP method verb for which this route applies
     * @param string $uri The string URI
     * @param callable|\Aerys\Middleware|\Aerys\Bootable $actions The action(s) to invoke upon matching this route
     * @throws \DomainException on invalid empty parameters
     * @return self
     */
    public function route(string $method, string $uri, ...$actions): Router {
        if ($this->state !== Server::STOPPED) {
            throw new \LogicException(
                "Cannot add routes once the server has started"
            );
        }
        if ($method === "") {
            throw new \DomainException(
                __METHOD__ . " requires a non-empty string HTTP method at Argument 1"
            );
        }
        if (empty($actions)) {
            throw new \DomainException(
                __METHOD__ . " requires at least one callable route action or middleware at Argument 3"
            );
        }

        $actions = array_merge($this->actions, $actions);

        $uri = "/" . ltrim($uri, "/");
        if (substr($uri, -2) === "/?") {
            $canonicalUri = substr($uri, 0, -2);
            $redirectUri = substr($uri, 0, -1);
            $this->routes[] = [$method, $canonicalUri, $actions];
            $this->routes[] = [$method, $redirectUri, self::CANONICAL_REDIRECT];
        } else {
            $this->routes[] = [$method, $uri, $actions];
        }

        return $this;
    }

    public function boot(Server $server, Logger $logger) {
        $server->attach($this);
        $this->bootLoader = function(Bootable $bootable) use ($server, $logger) {
            return $bootable->boot($server, $logger);
        };

        return [$this, "__invoke"];
    }

    private function bootRouteTarget($actions): array {
        $middlewares = [];
        $applications = [];
        $booted = [];

        if ($actions === self::CANONICAL_REDIRECT) {
            $actions = [function (Request $request, Response $response) {
                $uri = $request->getUri();
                if (stripos($uri, "?")) {
                    list($path, $query) = explode("?", $uri, 2);
                    $path = rtrim($path, "/");
                    $redirectTo = "{$path}?{$query}";
                } else {
                    $redirectTo = $path = substr($uri, 0, -1);
                }
                $response->setStatus(HTTP_STATUS["FOUND"]);
                $response->setHeader("Location", $redirectTo);
                $response->setHeader("Content-Type", "text/plain; charset=utf-8");
                $response->end("Canonical resource URI: {$path}");
            }];
        }


        foreach ($actions as $key => $action) {
            if ($action instanceof Bootable) {
                /* don't ever boot a Bootable twice */
                $hash = spl_object_hash($action);
                if (!array_key_exists($hash, $booted)) {
                    $booted[$hash] = ($this->bootLoader)($action);
                }
                $action = $booted[$hash];
            }
            if ($action instanceof Middleware) {
                $middlewares[] = [$action, "do"];
            } elseif (is_array($action) && $action[0] instanceof Middleware) {
                $middlewares[] = [$action[0], "do"];
            }
            if (is_callable($action)) {
                $applications[] = $action;
            }
        }

        if (empty($applications[1])) {
            if (empty($applications[0])) {
                throw new \DomainException(
                    "Each route must have at least one callable"
                );
            } else {
                return [$applications[0], $middlewares];
            }
        }

        return [function(Request $request, Response $response, array $args) use ($applications) {
            foreach ($applications as $application) {
                $result = $application($request, $response, $args);
                if ($result instanceof \Generator) {
                    yield from $result;
                }
                if ($response->state() & Response::STARTED) {
                    return;
                }
            }
        }, $middlewares];
    }

    /**
     * React to server state changes
     *
     * Here we generate our dispatcher when the server notifies us that it is
     * ready to start (Server::STARTING).
     *
     * @param Server $server
     * @return \Amp\Promise
     */
    public function update(Server $server): Promise {
        switch ($this->state = $server->state()) {
            case Server::STOPPED:
                $this->routeDispatcher = null;
                break;
            case Server::STARTING:
                if (empty($this->routes)) {
                    return new Failure(new \DomainException(
                        "Router start failure: no routes registered"
                    ));
                }
                $this->routeDispatcher = simpleDispatcher(function ($rc) use ($server) {
                    $this->buildRouter($rc, $server);
                });
                break;
        }

        return new Success;
    }

    private function buildRouter(RouteCollector $rc, Server $server) {
        $allowedMethods = [];
        foreach ($this->routes as list($method, $uri, $actions)) {
            $allowedMethods[] = $method;
            $rc->addRoute($method, $uri, $this->bootRouteTarget($actions));
        }
        $originalMethods = $server->getOption("allowedMethods");
        if ($server->getOption("normalizeMethodCase")) {
            $allowedMethods = array_map("strtoupper", $allowedMethods);
        }
        $allowedMethods = array_merge($allowedMethods, $originalMethods);
        $allowedMethods = array_unique($allowedMethods);
        $server->setOption("allowedMethods", $allowedMethods);
    }
}
