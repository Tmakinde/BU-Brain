<?php

namespace App\Modules\Ingestion\Chunkers;

/**
 * Chunks PHP config files into logical units.
 *
 * Small config files (< 100 lines) are returned as a single chunk.
 * Large config files are split using a three-tier strategy:
 *
 *   Tier 1 — Top-level assignment chunking
 *             Splits at each $conf['key'] = ... or return ['group' => ...]
 *             boundary. Never splits inside a value — every chunk is a
 *             complete, valid PHP expression.
 *
 *   Tier 2 — Key prefix grouping
 *             Groups scalar $conf assignments by the prefix before the first
 *             underscore. e.g. $conf['billing_tax'] and $conf['billing_rate']
 *             both go into a 'billing' chunk.
 *
 *   Tier 3 — Fixed line-count chunks with overlap
 *             Last resort when no grouping pattern is detectable.
 */
class ConfigChunker
{
    private const SMALL_FILE_LINE_LIMIT  = 100;
    private const LARGE_ARRAY_LINE_LIMIT = 60;
    private const FALLBACK_CHUNK_LINES   = 80;
    private const FALLBACK_OVERLAP_LINES = 10;

    public function chunk(string $content, string $filePath): array
    {
        $lineCount = substr_count($content, "\n");

        if ($lineCount < self::SMALL_FILE_LINE_LIMIT) {
            return $this->wholeFile($content);
        }

        return $this->chunkLargeConfig($content);
    }

    // =========================================================================
    // Tier selection
    // =========================================================================

    private function chunkLargeConfig(string $content): array
    {
        $chunks = $this->chunkByTopLevelAssignments($content);
        if (!empty($chunks)) {
            return $chunks;
        }

        if (str_contains($content, '$conf[')) { // I need to make this config driven at some point
            $chunks = $this->chunkByKeyPrefix($content);
            if (!empty($chunks)) {
                return $chunks;
            }
        }

        return $this->chunkByLineCount($content);
    }

    // =========================================================================
    // Tier 1 — top-level assignment chunking
    // =========================================================================

    private function chunkByTopLevelAssignments(string $content): array
    {
        $chunks            = [];
        $lines             = explode("\n", $content);
        $currentChunkLines = [];
        $bracketDepth      = 0;
        $inAssignment      = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            $isNewAssignment = preg_match('/^\$conf\[\'[^\']+\'\]\s*=/', $trimmed)
                || preg_match('/^return\s*\[/', $trimmed)
                || ($bracketDepth === 0 && preg_match('/^\'[^\']+\'\s*=>/', $trimmed));

            if ($isNewAssignment && $bracketDepth === 0 && !empty($currentChunkLines)) {
                $chunks            = array_merge($chunks, $this->finaliseChunk($currentChunkLines));
                $currentChunkLines = [];
                $inAssignment      = true;
            }

            $currentChunkLines[] = $line;

            $bracketDepth += substr_count($line, '[') - substr_count($line, ']');
            $bracketDepth += substr_count($line, '{') - substr_count($line, '}');
            $bracketDepth  = max(0, $bracketDepth);

            if ($bracketDepth === 0 && str_ends_with(rtrim($trimmed), ';') && $inAssignment) {
                $chunks            = array_merge($chunks, $this->finaliseChunk($currentChunkLines));
                $currentChunkLines = [];
                $inAssignment      = false;
            }
        }

        if (!empty($currentChunkLines)) {
            $chunks = array_merge($chunks, $this->finaliseChunk($currentChunkLines));
        }

        return $chunks;
    }

    private function finaliseChunk(array $lines): array
    {
        $code = trim(implode("\n", $lines));

        if (strlen($code) <= 30) {
            return [];
        }

        if (count($lines) > self::LARGE_ARRAY_LINE_LIMIT) {
            return $this->chunkLargeArrayValue($lines);
        }

        return [$this->buildChunk($code, $lines)];
    }

    private function chunkLargeArrayValue(array $lines): array
    {
        $chunks       = [];
        $currentLines = [];
        $depth        = 0;
        $firstLine    = true;
        $header       = $lines[0] ?? '';

        foreach ($lines as $line) {
            $depth += substr_count($line, '[') - substr_count($line, ']');
            $depth += substr_count($line, '{') - substr_count($line, '}');
            $depth  = max(0, $depth);

            if ($depth === 1 && !$firstLine && preg_match('/^\s*\'[^\']+\'\s*=>/', $line)) {
                if (!empty($currentLines)) {
                    $code = trim($header . "\n" . implode("\n", $currentLines));
                    if (strlen($code) > 30) {
                        $chunks[] = $this->buildChunk($code, $currentLines);
                    }
                    $currentLines = [];
                }
            }

            $currentLines[] = $line;
            $firstLine      = false;
        }

        if (!empty($currentLines)) {
            $code = trim($header . "\n" . implode("\n", $currentLines));
            if (strlen($code) > 30) {
                $chunks[] = $this->buildChunk($code, $currentLines);
            }
        }

        return empty($chunks)
            ? [$this->buildChunk(implode("\n", $lines), $lines)]
            : $chunks;
    }

    // =========================================================================
    // Tier 2 — key prefix grouping
    // =========================================================================

    private function chunkByKeyPrefix(string $content): array
    {
        $lines  = explode("\n", $content);
        $groups = [];

        foreach ($lines as $line) {
            if (preg_match('/\$conf\[\'([^\']+)\'/', $line, $match)) {
                $prefix            = explode('_', $match[1])[0];
                $groups[$prefix][] = $line;
            } else {
                $lastPrefix = array_key_last($groups);
                if ($lastPrefix !== null) {
                    $groups[$lastPrefix][] = $line;
                }
            }
        }

        $chunks = [];
        foreach ($groups as $prefix => $groupLines) {
            $code = trim(implode("\n", $groupLines));
            if (strlen($code) > 30) {
                $chunks[] = [
                    'code'        => $code,
                    'class_name'  => null,
                    'method_name' => $prefix,
                    'code_type'   => 'config',
                ];
            }
        }

        return $chunks;
    }

    // =========================================================================
    // Tier 3 — fixed line-count fallback
    // =========================================================================

    private function chunkByLineCount(string $content): array
    {
        $lines  = explode("\n", $content);
        $total  = count($lines);
        $chunks = [];
        $step   = max(1, self::FALLBACK_CHUNK_LINES - self::FALLBACK_OVERLAP_LINES);

        for ($i = 0; $i < $total; $i += $step) {
            $code = trim(implode("\n", array_slice($lines, $i, self::FALLBACK_CHUNK_LINES)));
            if (strlen($code) > 30) {
                $chunks[] = [
                    'code'        => $code,
                    'class_name'  => null,
                    'method_name' => null,
                    'code_type'   => 'config',
                ];
            }
        }

        return empty($chunks) ? $this->wholeFile($content) : $chunks;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildChunk(string $code, array $lines): array
    {
        $keyName   = null;
        $firstLine = trim($lines[0] ?? '');

        if (preg_match('/\$conf\[\'([^\']+)\'/', $firstLine, $match)) {
            $keyName = $match[1];
        } elseif (preg_match('/\'([^\']+)\'\s*=>/', $firstLine, $match)) {
            $keyName = $match[1];
        }

        return [
            'code'        => $code,
            'class_name'  => null,
            'method_name' => $keyName,
            'code_type'   => 'config',
        ];
    }

    private function wholeFile(string $content): array
    {
        return [[
            'code'        => $content,
            'class_name'  => null,
            'method_name' => null,
            'code_type'   => 'config',
        ]];
    }
}