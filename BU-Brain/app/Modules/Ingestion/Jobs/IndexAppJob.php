<?php

namespace App\Modules\Ingestion\Jobs;

use App\Modules\Ingestion\IngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour for large codebases

    /**
     * Create a new job instance.
     *
     * @param string $appName The app name from app_registry
     */
    public function __construct(
        public string $appName
    ) {}

    /**
     * Execute the job.
     */
    public function handle(IngestionService $ingestionService): void
    {
        Log::info("IndexAppJob started", ['app_name' => $this->appName]);

        try {
            $stats = $ingestionService->indexApp($this->appName);

            Log::info("IndexAppJob completed successfully", [
                'app_name' => $this->appName,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error("IndexAppJob failed", [
                'app_name' => $this->appName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("IndexAppJob failed permanently", [
            'app_name' => $this->appName,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
