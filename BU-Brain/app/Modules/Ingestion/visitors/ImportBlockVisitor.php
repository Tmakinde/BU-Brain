<?php

namespace App\Modules\Ingestion\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Extracts all use/import statements from a PHP file's AST.
 *
 * Captures:
 *   use App\Services\BillingService;
 *   use App\Models\Invoice;
 *   use App\Repositories\PaymentRepository;
 *
 * This chunk tells you exactly what a class depends on —
 * critical for cross-app questions like "what does BillingService use?"
 *
 * Usage:
 *   $visitor = new ImportBlockVisitor();
 *   $traverser->addVisitor($visitor);
 *   $traverser->traverse($ast);
 *   $imports = $visitor->getImportBlock();
 */
class ImportBlockVisitor extends NodeVisitorAbstract
{
    private array $useStatements  = [];
    private ?string $namespace    = null;

    public function enterNode(Node $node): null
    {
        // Capture namespace declaration
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();
        }

        // Capture use statements
        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->useStatements[] = 'use ' . $use->name->toString()
                    . ($use->alias ? ' as ' . $use->alias->toString() : '')
                    . ';';
            }
        }

        // Capture grouped use statements: use App\Models\{Invoice, Customer};
        if ($node instanceof Node\Stmt\GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $this->useStatements[] = 'use ' . $prefix . '\\' . $use->name->toString()
                    . ($use->alias ? ' as ' . $use->alias->toString() : '')
                    . ';';
            }
        }

        return null;
    }

    /**
     * Returns the full import block as a formatted string.
     * Returns null if no use statements found.
     */
    public function getImportBlock(): ?string
    {
        if (empty($this->useStatements)) {
            return null;
        }

        $lines = [];

        if ($this->namespace) {
            $lines[] = 'namespace ' . $this->namespace . ';';
            $lines[] = '';
        }

        foreach ($this->useStatements as $statement) {
            $lines[] = $statement;
        }

        return implode("\n", $lines);
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getUseStatements(): array
    {
        return $this->useStatements;
    }
}