<?php

namespace App\Console\Commands;

use App\Modules\Ingestion\IngestionService;
use App\Modules\Registry\AppRegistryService;
use Illuminate\Console\Command;

class AppStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bu-brain:stats {app? : The app name to view stats for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'View indexing statistics for BU Brain apps';

    /**
     * Execute the console command.
     */
    public function handle(
        IngestionService $ingestionService,
        AppRegistryService $registryService
    ): int {
        $appName = $this->argument('app');

        if (!$appName) {
            return $this->showAllAppsStats($registryService, $ingestionService);
        }

        return $this->showSingleAppStats($appName, $ingestionService);
    }

    /**
     * Show stats for all apps.
     */
    private function showAllAppsStats(
        AppRegistryService $registryService,
        IngestionService $ingestionService
    ): int {
        $apps = $registryService->all();

        if ($apps->isEmpty()) {
            $this->error('No apps found in registry');
            return self::FAILURE;
        }

        $this->info("BU Brain — Indexing Statistics");
        $this->newLine();

        $tableData = [];

        foreach ($apps as $app) {
            try {
                $stats = $ingestionService->getAppStats($app->name);
                
                $tableData[] = [
                    $app->name,
                    $stats['total_chunks'],
                    $stats['last_indexed_at'] ? $stats['last_indexed_at']->diffForHumans() : 'Never',
                ];
            } catch (\Exception $e) {
                $tableData[] = [
                    $app->name,
                    'Error',
                    'N/A',
                ];
            }
        }

        $this->table(
            ['App Name', 'Total Chunks', 'Last Indexed'],
            $tableData
        );

        return self::SUCCESS;
    }

    /**
     * Show detailed stats for a single app.
     */
    private function showSingleAppStats(string $appName, IngestionService $ingestionService): int
    {
        try {
            $stats = $ingestionService->getAppStats($appName);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Statistics for: {$appName}");
        $this->newLine();

        $this->line("Total Chunks: {$stats['total_chunks']}");
        $this->line("Last Indexed: " . ($stats['last_indexed_at'] ? $stats['last_indexed_at']->format('Y-m-d H:i:s') : 'Never'));
        $this->newLine();

        if (!empty($stats['chunks_by_type'])) {
            $this->info("Chunks by Type:");
            $typeData = [];

            foreach ($stats['chunks_by_type'] as $type => $count) {
                $typeData[] = [$type, $count];
            }

            $this->table(['Type', 'Count'], $typeData);
        }

        return self::SUCCESS;
    }
}
