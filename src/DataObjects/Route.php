<?php

namespace Merloot\LaravelSwagger\DataObjects;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Route
{
    private $route;
    private $middleware;
    private $group;

    public function __construct(LaravelRoute $route)
    {
        $this->route = $route;
        $this->middleware = $this->formatMiddleware();
        $this->group = $this->group();

    }

    public function originalUri()
    {
        $uri = $this->route->uri();

        if (!Str::startsWith($uri, '/')) {
            $uri = '/' . $uri;
        }

        return $uri;
    }


    public function uri()
    {
        return strip_optional_char($this->originalUri());
    }

    public function group(){
        return $this->route->getPrefix();
    }

    public function middleware()
    {
        return $this->middleware;
    }

    public function action(): string
    {
        return $this->route->getActionName();
    }

    public function methods()
    {
        return array_map('strtolower', $this->route->methods());
    }

    protected function formatMiddleware()
    {
        $middleware = $this->route->getAction()['middleware'] ?? [];

        return array_map(function ($middleware) {
            return new Middleware($middleware);
        }, Arr::wrap($middleware));
    }
}
