<?php

namespace Merloot\LaravelSwagger;

use ReflectionMethod;
use Illuminate\Support\Str;
use Illuminate\Foundation\Http\FormRequest;
use phpDocumentor\Reflection\DocBlockFactory;

class Generator
{


    protected $config;
    protected $routeFilter;
    protected $docs;
    protected $route;
    protected $method;
    protected $docParser;
    protected $hasSecurityDefinitions;

    public function __construct($config, $routeFilter = null)
    {
        $this->config = $config;
        $this->routeFilter = $routeFilter;
        $this->docParser = DocBlockFactory::createInstance();
        $this->hasSecurityDefinitions = false;
    }

    public function generate()
    {
        $this->docs = $this->getBaseInfo();

        if ($this->config['parseSecurity'] ) {
            $this->docs['securityDefinitions'] = $this->generateSecurityDefinitions();
            $this->hasSecurityDefinitions = true;
        }

        foreach ($this->getAppRoutes() as $route) {
            $this->route = $route;
            if ($this->routeFilter && $this->isFilteredRoute()) {
                continue;
            }
            if (!isset($this->docs['paths'][$this->route->uri()])) {
                $this->docs['paths'][$this->route->uri()] = [];
            }

            foreach ($route->methods() as $method) {
                $this->method = $method;
                if (in_array($this->method, $this->config['ignoredMethods'])) {
                    continue;
                }

                $this->generatePath();
            }
        }

        var_dump($this->route->group());
        return $this->docs;
    }

    protected function getBaseInfo()
    {
        $baseInfo = [
            'swagger' => '2.0',
            'info' => [
                'title' => $this->config['title'],
                'description' => $this->config['description'],
                'version' => $this->config['appVersion'],
            ],
            'host' => $this->config['host'],
            'basePath' => $this->config['basePath'],
        ];

        if (!empty($this->config['schemes'])) {
            $baseInfo['schemes'] = $this->config['schemes'];
        }

        if (!empty($this->config['consumes'])) {
            $baseInfo['consumes'] = $this->config['consumes'];
        }

        if (!empty($this->config['produces'])) {
            $baseInfo['produces'] = $this->config['produces'];
        }

        $baseInfo['paths'] = [];

        return $baseInfo;
    }

    protected function getAppRoutes()
    {
        return array_map(function ($route) {
            return new DataObjects\Route($route);
        }, app('router')->getRoutes()->getRoutes());
    }

    protected function generateSecurityDefinitions()
    {

        $authFlow = $this->config['authFlow'];

        $this->validateAuthFlow($authFlow);

        $securityDefinition = [
            'jwt' => [
                'type'      => 'apiKey',
                'name'      => 'Authorization',
                'in'        => 'header',
            ],
        ];

//        $securityDefinition['jwt']['scopes'] = $this->generateOauthScopes();

        return $securityDefinition;
    }

    protected function generatePath()
    {
        $actionInstance = $this->getActionClassInstance();
        $docBlock = $actionInstance ? ($actionInstance->getDocComment() ?: '') : '';

        [$isDeprecated, $summary, $description] = $this->parseActionDocBlock($docBlock);

        $this->docs['paths'][$this->route->uri()][$this->method] = [
            'summary' => $summary,
            'description' => $description,
            'deprecated' => $isDeprecated,
            'responses' => [
                '200' => [
                    'description' => 'OK',
                ],
            ],
        ];

        $this->addActionParameters();

        if ($this->hasSecurityDefinitions) {
            $this->addActionScopes();
        }
    }

    protected function addActionParameters()
    {
        $rules = $this->getFormRules() ?: [];

        $parameters = (new Parameters\PathParameterGenerator($this->route->originalUri()))->getParameters();
        if (!empty($rules)) {
            $parameterGenerator = $this->getParameterGenerator($rules);

            $parameters = array_merge($parameters, $parameterGenerator->getParameters());
        }

        if (!empty($parameters)) {
            $this->docs['paths'][$this->route->uri()][$this->method]['parameters'] = $parameters;
        }
    }

    protected function addActionScopes()
    {
        foreach ($this->route->middleware() as $middleware) {
            if ($this->isPassportScopeMiddleware($middleware)) {
                $this->docs['paths'][$this->route->uri()][$this->method]['security'][] = ['jwt'=>[]];

            }
        }
    }

    protected function getFormRules(): array
    {
        $action_instance = $this->getActionClassInstance();
        $array = [];
        if (!$action_instance) {
            return [];
        }

        $parameters = $action_instance->getParameters();

        foreach ($parameters as $parameter) {
            $class_name = $name = $parameter->getType() && !$parameter->getType()->isBuiltin()
                ? new \ReflectionClass($parameter->getType()->getName())
                : null;
            if (is_subclass_of($parameter->getType()->getName(), FormRequest::class)) {
                $array = (new $class_name->name)->rules();
            }
        }
        return $array;
    }

    protected function getParameterGenerator($rules)
    {
        switch ($this->method) {
            case 'post':
            case 'put':
            case 'patch':
                return new Parameters\BodyParameterGenerator($rules);
            default:
                return new Parameters\QueryParameterGenerator($rules);
        }
    }

    private function getActionClassInstance(): ?ReflectionMethod
    {
        [$class, $method] = Str::parseCallback($this->route->action());

        if (!$class || !$method) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    private function parseActionDocBlock(string $docBlock)
    {
        if (empty($docBlock) || !$this->config['parseDocBlock']) {
            return [false, '', ''];
        }

        try {
            $parsedComment = $this->docParser->create($docBlock);

            $isDeprecated = $parsedComment->hasTag('deprecated');

            $summary = $parsedComment->getSummary();
            $description = (string) $parsedComment->getDescription();

            return [$isDeprecated, $summary, $description];
        } catch (\Exception $e) {
            return [false, '', ''];
        }
    }

    private function isFilteredRoute()
    {
        return !preg_match('/^' . preg_quote($this->routeFilter, '/') . '/', $this->route->uri());
    }


    private function generateOauthScopes() {
        $scopes = [];

        return array_combine(array_column($scopes, 'id'), array_column($scopes, 'description'));
    }

    private function validateAuthFlow(string $flow) {
        if (!in_array($flow, ['password', 'application', 'implicit', 'accessCode','apiKey'])) {
            throw new LaravelSwaggerException('Invalid OAuth flow passed');
        }
    }

    private function isPassportScopeMiddleware(DataObjects\Middleware $middleware)
    {

        $resolver = $this->getMiddlewareResolver($middleware->name());


        return $resolver;
    }

    private function getMiddlewareResolver(string $middleware)
    {
        $middlewareMap = app('router')->getMiddleware();

        return $middlewareMap[$middleware] ?? null;
    }
}
