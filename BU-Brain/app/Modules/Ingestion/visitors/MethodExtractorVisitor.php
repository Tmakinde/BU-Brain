<?php

namespace App\Modules\Ingestion\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Extracts all methods from a PHP class via AST traversal.
 *
 * Each extracted method includes:
 *   - name: the method name
 *   - code: the full reconstructed method source code
 *   - visibility: public | protected | private
 *   - is_static: bool
 *   - start_line: line number in original file
 *   - end_line: line number in original file
 *
 * This replaces the fragile brace-matching approach used in the regex version.
 * The AST guarantees correct method boundaries regardless of nested structures,
 * string literals containing braces, or comments.
 *
 * Usage:
 *   $visitor = new MethodExtractorVisitor($originalSource);
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $methods = $visitor->getMethods();
 */
class MethodExtractorVisitor extends NodeVisitorAbstract
{
    private array $methods        = [];
    private array $sourceLines;
    private PrettyPrinter $printer;

    public function __construct(string $source)
    {
        $this->sourceLines = explode("\n", $source);
        $this->printer     = new PrettyPrinter();
    }

    public function enterNode(Node $node): null
    {
        if (!($node instanceof Node\Stmt\ClassMethod)) {
            return null;
        }

        // Extract exact source lines using AST position data
        // This is far more accurate than brace-matching regex
        $startLine = $node->getStartLine() - 1; // convert to 0-indexed
        $endLine   = $node->getEndLine();

        $methodLines = array_slice($this->sourceLines, $startLine, $endLine - $startLine);
        $methodCode  = implode("\n", $methodLines);

        // Determine visibility
        $visibility = 'public';
        if ($node->isProtected()) $visibility = 'protected';
        if ($node->isPrivate())   $visibility = 'private';

        $this->methods[] = [
            'name'       => $node->name->toString(),
            'code'       => $methodCode,
            'visibility' => $visibility,
            'is_static'  => $node->isStatic(),
            'start_line' => $node->getStartLine(),
            'end_line'   => $node->getEndLine(),
        ];

        return null;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }
}