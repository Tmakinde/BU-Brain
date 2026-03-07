<?php

namespace App\Console\Commands;

use App\Modules\Query\Services\QueryService;
use App\Modules\Query\Services\RetrievalService;
use App\Modules\Query\Services\ContextBuilder;
use Illuminate\Console\Command;

class SearchCommand extends Command
{
    protected $signature = 'bu-brain:search 
                            {query : The search query}
                            {--app= : Filter by app name}
                            {--limit=10 : Maximum number of results}
                            {--min-similarity=0.5 : Minimum similarity threshold (0-1)}
                            {--format=cli : Output format (cli, json, llm)}';

    protected $description = 'Search indexed codebases using semantic search';

    public function handle(
        QueryService $queryService,
        RetrievalService $retrievalService,
        ContextBuilder $contextBuilder
    ): int {
        $query = $this->argument('query');
        $appName = $this->option('app');
        $limit = (int) $this->option('limit');
        $minSimilarity = (float) $this->option('min-similarity');
        $format = $this->option('format');

        $this->info("🔍 Searching for: \"{$query}\"");
        
        if ($appName) {
            $this->info("   Filtering by app: {$appName}");
        }
        
        $this->newLine();

        // Clean and embed the query
        $cleanedQuery = $queryService->cleanQuery($query);
        
        $this->info('⏳ Generating query embedding...');
        $queryEmbedding = $queryService->embedQuery($cleanedQuery);

        // Search for similar chunks
        $this->info('🔎 Searching vector database...');
        $chunks = $retrievalService->search($queryEmbedding, [
            'app_name' => $appName,
            'limit' => $limit,
            'min_similarity' => $minSimilarity
        ]);

        // Deduplicate results
        $chunks = $contextBuilder->deduplicate($chunks);

        // Format and display results
        switch ($format) {
            case 'json':
                $output = $contextBuilder->buildForAPI($chunks);
                $this->line(json_encode($output, JSON_PRETTY_PRINT));
                break;

            case 'llm':
                $output = $contextBuilder->buildForLLM($chunks);
                $this->line($output);
                break;

            case 'cli':
            default:
                $output = $contextBuilder->buildForCLI($chunks);
                $this->line($output);
                break;
        }

        return self::SUCCESS;
    }
}
