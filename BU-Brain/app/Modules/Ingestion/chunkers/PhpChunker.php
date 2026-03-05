<?php

namespace App\Modules\Ingestion\Chunkers;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Error as PhpParserError;
use App\Modules\Ingestion\Visitors\ClassNameVisitor;
use App\Modules\Ingestion\Visitors\ImportBlockVisitor;
use App\Modules\Ingestion\Visitors\MethodExtractorVisitor;
use App\Modules\Ingestion\Visitors\RouteVisitor;
use PhpParser\PhpVersion;

/**
 * Chunks PHP files into logical units using AST traversal.
 *
 * Handles:
 *   - Route files        → one chunk per Route:: definition
 *   - Migration files    → whole file (one schema change per migration)
 *   - Config files       → delegates to ConfigChunker
 *   - Template files     → PHP logic block + JS blocks extracted separately
 *   - Class files        → import block + one chunk per method
 *   - Procedural files   → whole file
 */
class PhpChunker
{
    private \PhpParser\Parser $parser;

    public function __construct(private ConfigChunker $configChunker)
    {
        $this->parser = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.1'));
    }

    /**
     * Chunk a PHP file.
     */
    public function chunk(string $content, string $filePath): array
    {
        if (str_contains($filePath, 'routes/')) {
            return $this->chunkRouteFile($content, $filePath);
        }

        if (str_contains($filePath, 'migrations/')) {
            return $this->wholeFile($content, 'migration');
        }

        if (str_contains($filePath, 'config/')) {
            return $this->configChunker->chunk($content, $filePath);
        }

        if ($this->isMixedTemplate($content)) {
            return $this->chunkTemplate($content, $filePath);
        }

        return $this->chunkClassFile($content, $filePath);
    }

    // =========================================================================
    // Route files
    // =========================================================================

    /**
     * Extract one chunk per Route:: definition using AST.
     */
    private function chunkRouteFile(string $content, string $filePath): array
    {
        $ast = $this->parse($content, $filePath);

        if ($ast === null) {
            return $this->wholeFile($content, 'route');
        }

        $traverser    = new NodeTraverser();
        $routeVisitor = new RouteVisitor($content);
        $traverser->addVisitor($routeVisitor);
        $traverser->traverse($ast);

        $routes = $routeVisitor->getRoutes();

        if (count($routes) < 2) {
            return $this->wholeFile($content, 'route');
        }

        return array_map(fn($route) => [
            'code'        => $route['code'],
            'class_name'  => null,
            'method_name' => $route['path'] ?? null,
            'code_type'   => 'route',
        ], $routes);
    }

    // =========================================================================
    // Class files
    // =========================================================================

    /**
     * Extract import block + one chunk per method using AST.
     * Falls back to whole file if parsing fails or no class is found.
     */
    private function chunkClassFile(string $content, string $filePath): array
    {
        $ast = $this->parse($content, $filePath);

        if ($ast === null) {
            return $this->wholeFile($content);
        }

        // Run all three visitors in a single traversal pass
        $traverser     = new NodeTraverser();
        $classVisitor  = new ClassNameVisitor();
        $importVisitor = new ImportBlockVisitor();
        $methodVisitor = new MethodExtractorVisitor($content);

        $traverser->addVisitor($classVisitor);
        $traverser->addVisitor($importVisitor);
        $traverser->addVisitor($methodVisitor);
        $traverser->traverse($ast);

        $className = $classVisitor->getClassName();
        $methods   = $methodVisitor->getMethods();
        $codeType  = $this->inferCodeType($filePath);
        $chunks    = [];

        // Import block — always first if use statements exist
        $importBlock = $importVisitor->getImportBlock();
        if ($importBlock) {
            $chunks[] = [
                'code'        => $importBlock,
                'class_name'  => $className,
                'method_name' => '__imports',
                'code_type'   => 'imports',
            ];
        }

        // One chunk per method
        if ($className && !empty($methods)) {
            foreach ($methods as $method) {
                $chunks[] = [
                    'code'        => $method['code'],
                    'class_name'  => $className,
                    'method_name' => $method['name'],
                    'code_type'   => $codeType,
                ];
            }
            return $chunks;
        }

        // Class found but no methods
        if ($className) {
            $chunks[] = [
                'code'        => $content,
                'class_name'  => $className,
                'method_name' => null,
                'code_type'   => $codeType,
            ];
            return $chunks;
        }

        // No class — procedural file
        // Return import chunk (if any) + whole file
        $chunks[] = $this->wholeFile($content)[0];
        return $chunks;
    }

    // =========================================================================
    // Template files
    // =========================================================================

    /**
     * Detect mixed PHP/HTML template files.
     */
    private function isMixedTemplate(string $content): bool
    {
        return (bool) preg_match('/<html|<body|<table|<div|<!DOCTYPE/i', $content)
            && str_contains($content, '<?php');
    }

    /**
     * Extract PHP logic and inline JS blocks as separate chunks.
     */
    private function chunkTemplate(string $content, string $filePath): array
    {
        $chunks = [];

        // PHP logic blocks
        preg_match_all('/<\?php([\s\S]*?)\?>/i', $content, $phpBlocks);
        if (!empty($phpBlocks[1])) {
            $phpLogic = implode("\n", array_map('trim', $phpBlocks[1]));
            if (strlen(trim($phpLogic)) > 30) {
                $chunks[] = [
                    'code'        => $phpLogic,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'template_logic',
                ];
            }
        }

        // Inline JavaScript blocks
        preg_match_all('/<script[^>]*>([\s\S]*?)<\/script>/i', $content, $jsBlocks);
        foreach ($jsBlocks[1] ?? [] as $jsBlock) {
            $jsBlock = trim($jsBlock);
            if (strlen($jsBlock) > 30) {
                $chunks[] = [
                    'code'        => $jsBlock,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'template_script',
                ];
            }
        }

        return empty($chunks)
            ? $this->wholeFile($content, 'template')
            : $chunks;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Parse PHP source into an AST.
     * Returns null on failure — callers fall back to whole-file chunking.
     */
    private function parse(string $content, string $filePath): ?array
    {
        try {
            return $this->parser->parse($content);
        } catch (PhpParserError $e) {
            logger()->warning("PhpChunker: failed to parse {$filePath}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Infer code type from file path.
     */
    private function inferCodeType(string $filePath): string
    {
        if (str_contains($filePath, 'Services/'))     return 'service';
        if (str_contains($filePath, 'Controllers/'))  return 'controller';
        if (str_contains($filePath, 'Models/'))       return 'model';
        if (str_contains($filePath, 'Jobs/'))         return 'job';
        if (str_contains($filePath, 'Repositories/')) return 'repository';
        if (str_contains($filePath, 'Http/'))         return 'http';
        return 'class';
    }

    private function wholeFile(string $content, string $codeType = 'file'): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => $codeType,
        ]];
    }
}