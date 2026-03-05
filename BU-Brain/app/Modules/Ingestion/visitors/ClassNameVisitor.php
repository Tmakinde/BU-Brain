<?php

namespace App\Modules\Ingestion\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Extracts the class name from a PHP file's AST.
 *
 * Usage:
 *   $visitor = new ClassNameVisitor();
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $className = $visitor->getClassName();
 */
class ClassNameVisitor extends NodeVisitorAbstract
{
    private ?string $className = null;

    public function enterNode(Node $node): null
    {
        // Captures: class Foo, abstract class Foo, final class Foo
        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name !== null) {
                $this->className = $node->name->toString();
            }
        }

        return null;
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }
}