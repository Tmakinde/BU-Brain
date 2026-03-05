<?php

namespace App\Modules\Ingestion\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Extracts Laravel Route:: definitions from a route file's AST.
 *
 * Captures:
 *   Route::get('/path', [Controller::class, 'method']);
 *   Route::post('/path', function() { ... });
 *   Route::resource('resource', Controller::class);
 *   Route::apiResource('resource', Controller::class);
 *
 * Each route is extracted with:
 *   - method: get | post | put | patch | delete | resource | apiResource | etc.
 *   - path: the route URI string (if statically defined)
 *   - code: the full route definition source
 *   - start_line / end_line
 *
 * Usage:
 *   $visitor = new RouteVisitor($source);
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $routes = $visitor->getRoutes();
 */
class RouteVisitor extends NodeVisitorAbstract
{
    private array $routes = [];
    private array $sourceLines;

    private const ROUTE_METHODS = [
        'get', 'post', 'put', 'patch', 'delete',
        'any', 'match', 'resource', 'apiResource',
        'redirect', 'view', 'singleton',
    ];

    public function __construct(string $source)
    {
        $this->sourceLines = explode("\n", $source);
    }

    public function enterNode(Node $node): null
    {
        // Match static method calls: Route::get(...), Route::post(...)
        if (!($node instanceof Node\Expr\StaticCall)) {
            return null;
        }

        // Must be on the Route class
        if (!($node->class instanceof Node\Name)) {
            return null;
        }

        if ($node->class->toString() !== 'Route') {
            return null;
        }

        // Must be a known route method
        if (!($node->name instanceof Node\Identifier)) {
            return null;
        }

        $method = $node->name->toString();

        if (!in_array($method, self::ROUTE_METHODS)) {
            return null;
        }

        // Extract the route URI if it's a string literal
        $path = null;
        if (!empty($node->args) && $node->args[0]->value instanceof Node\Scalar\String_) {
            $path = $node->args[0]->value->value;
        }

        // Extract exact source lines
        $startLine  = $node->getStartLine() - 1;
        $endLine    = $node->getEndLine();
        $routeLines = array_slice($this->sourceLines, $startLine, $endLine - $startLine);
        $routeCode  = implode("\n", $routeLines);

        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'code'       => $routeCode,
            'start_line' => $node->getStartLine(),
            'end_line'   => $node->getEndLine(),
        ];

        return null;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}