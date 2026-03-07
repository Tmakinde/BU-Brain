<?php

namespace App\Console\Commands;

use App\Modules\Ingestion\Jobs\IndexAppJob;
use App\Modules\Registry\AppRegistryService;
use Illuminate\Console\Command;

class IndexAppCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bu-brain:index {app? : The app name to index} {--all : Index all apps}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index an application codebase into BU Brain';

    /**
     * Execute the console command.
     */
    public function handle(AppRegistryService $registryService): int
    {
        if ($this->option('all')) {
            return $this->indexAllApps($registryService);
        }

        $appName = $this->argument('app');

        if (!$appName) {
            $this->error('Please specify an app name or use --all flag');
            $this->info('Available apps:');
            
            foreach ($registryService->all() as $app) {
                $this->line("  - {$app->name}");
            }

            return self::FAILURE;
        }

        return $this->indexSingleApp($appName, $registryService);
    }

    /**
     * Index a single app.
     */
    private function indexSingleApp(string $appName, AppRegistryService $registryService): int
    {
        $app = $registryService->findByName($appName);

        if (!$app) {
            $this->error("App '{$appName}' not found in registry");
            return self::FAILURE;
        }

        $this->info("Dispatching indexing job for: {$appName}");
        $this->info("Source: {$app->source_path}");

        IndexAppJob::dispatch($appName);

        $this->info("Job dispatched successfully!");
        $this->comment("Monitor progress with: php artisan queue:work");

        return self::SUCCESS;
    }

    /**
     * Index all registered apps.
     */
    private function indexAllApps(AppRegistryService $registryService): int
    {
        $apps = $registryService->all();

        if ($apps->isEmpty()) {
            $this->error('No apps found in registry');
            return self::FAILURE;
        }

        $this->info("Dispatching indexing jobs for {$apps->count()} apps...");

        foreach ($apps as $app) {
            IndexAppJob::dispatch($app->name);
            $this->line("  ✓ {$app->name}");
        }

        $this->info("All jobs dispatched!");
        $this->comment("Monitor progress with: php artisan queue:work");

        return self::SUCCESS;
    }
}
