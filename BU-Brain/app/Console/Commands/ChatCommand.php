<?php

namespace App\Console\Commands;

use App\Modules\Agent\Services\AgentService;
use App\Modules\Agent\Services\ConversationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bu-brain:chat
                            {--app= : The app to query (optional)}
                            {--session= : Resume a previous session (optional)}
                            {--new : Start a new session (clears existing if session ID provided)}
                            {--history : Show conversation history for the session}
                            {--sessions : List recent sessions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive chat with BU-Brain agent about your codebases';

    private AgentService $agentService;
    private ConversationService $conversationService;
    private ?string $sessionId = null;
    private ?string $appName = null;

    public function __construct(AgentService $agentService)
    {
        parent::__construct();
        $this->agentService = $agentService;
        $this->conversationService = $agentService->getConversationService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Handle --sessions flag
        if ($this->option('sessions')) {
            return $this->showRecentSessions();
        }

        // Initialize session
        $this->initializeSession();

        // Handle --history flag
        if ($this->option('history')) {
            return $this->showHistory();
        }

        // Show welcome message
        $this->showWelcome();

        // Health check
        $health = $this->agentService->healthCheck();
        if (!$health['ready']) {
            $this->error('Agent is not ready:');
            foreach ($health['issues'] as $issue) {
                $this->line("  - {$issue}");
            }
            return 1;
        }

        $this->info("Using LLM: {$health['llm_model']}");
        $this->newLine();

        // Start interactive chat loop
        while (true) {
            $query = $this->ask('You');

            if (empty($query)) {
                continue;
            }

            // Handle special commands
            if ($this->handleCommand($query)) {
                continue;
            }

            // Process query with agent
            $this->processQuery($query);
        }

        return 0;
    }

    /**
     * Initialize the chat session.
     */
    private function initializeSession(): void
    {
        $this->appName = $this->option('app');
        $this->sessionId = $this->option('session');

        // Start new session or clear existing
        if ($this->option('new')) {
            if ($this->sessionId) {
                $deleted = $this->conversationService->clearSession($this->sessionId);
                $this->info("Cleared {$deleted} messages from session {$this->sessionId}");
            }
            $this->sessionId = $this->conversationService->startSession($this->appName);
        } elseif ($this->sessionId) {
            // Resume existing session
            if (!$this->conversationService->sessionExists($this->sessionId)) {
                $this->warn("Session {$this->sessionId} not found. Starting new session.");
                $this->sessionId = $this->conversationService->startSession($this->appName);
            } else {
                // Get app context from session if not specified
                if (!$this->appName) {
                    $this->appName = $this->conversationService->getSessionApp($this->sessionId);
                }
            }
        } else {
            // Create new session
            $this->sessionId = $this->conversationService->startSession($this->appName);
        }
    }

    /**
     * Show welcome message.
     */
    private function showWelcome(): void
    {
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║              Welcome to BU-Brain Chat                      ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $this->line("Session ID: <comment>{$this->sessionId}</comment>");
        if ($this->appName) {
            $this->line("App Context: <comment>{$this->appName}</comment>");
        } else {
            $this->line("App Context: <comment>all apps</comment>");
        }
        
        $this->newLine();
        $this->line('Commands:');
        $this->line('  /help     - Show available commands');
        $this->line('  /history  - Show conversation history');
        $this->line('  /clear    - Clear conversation history');
        $this->line('  /app      - Change app context');
        $this->line('  /exit     - Exit chat');
        $this->newLine();
    }

    /**
     * Process a user query.
     */
    private function processQuery(string $query): void
    {
        $this->info('Thinking...');

        try {
            $result = $this->agentService->ask(
                query: $query,
                sessionId: $this->sessionId,
                appName: $this->appName
            );

            $this->newLine();
            $this->line('<fg=green>Assistant:</>');
            $this->line($result['response']);
            $this->newLine();
            
            $this->line("<fg=gray>Used {$result['chunks_used']} code chunks</>");
            $this->newLine();

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Handle special commands.
     *
     * @return bool True if command was handled
     */
    private function handleCommand(string $input): bool
    {
        if (!str_starts_with($input, '/')) {
            return false;
        }

        $command = strtolower(trim($input));

        switch ($command) {
            case '/exit':
            case '/quit':
                $this->info('Goodbye!');
                exit(0);

            case '/help':
                $this->showWelcome();
                return true;

            case '/history':
                $this->showHistory();
                return true;

            case '/clear':
                $deleted = $this->conversationService->clearSession($this->sessionId);
                $this->info("Cleared {$deleted} messages from session.");
                $this->sessionId = $this->conversationService->startSession($this->appName);
                $this->line("New session ID: <comment>{$this->sessionId}</comment>");
                $this->newLine();
                return true;

            case '/app':
                $newApp = $this->ask('Enter app name (or leave empty for all apps)');
                $this->appName = empty($newApp) ? null : $newApp;
                $appDisplay = $this->appName ?? 'all apps';
                $this->info("App context changed to: {$appDisplay}");
                $this->newLine();
                return true;

            default:
                $this->warn("Unknown command: {$command}");
                $this->line('Type /help for available commands');
                $this->newLine();
                return true;
        }
    }

    /**
     * Show conversation history.
     */
    private function showHistory(): int
    {
        $history = $this->conversationService->getHistory($this->sessionId);

        if ($history->isEmpty()) {
            $this->info('No conversation history for this session.');
            return 0;
        }

        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                  Conversation History                      ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        foreach ($history as $message) {
            if ($message->role === 'system') {
                continue;
            }

            $roleDisplay = $message->role === 'user' ? '<fg=cyan>You</>' : '<fg=green>Assistant</>';
            $this->line("{$roleDisplay}:");
            $this->line(Str::limit($message->content, 200));
            $this->line("<fg=gray>{$message->created_at->diffForHumans()}</>");
            $this->newLine();
        }

        return 0;
    }

    /**
     * Show recent sessions.
     */
    private function showRecentSessions(): int
    {
        $sessions = $this->conversationService->getRecentSessions(20);

        if ($sessions->isEmpty()) {
            $this->info('No recent sessions found.');
            return 0;
        }

        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                     Recent Sessions                        ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $headers = ['Session ID', 'App', 'Messages', 'Last Activity'];
        $rows = [];

        foreach ($sessions as $session) {
            $summary = $this->conversationService->getSessionSummary($session->session_id);
            
            $rows[] = [
                Str::limit($session->session_id, 36),
                $session->working_app ?? 'all',
                $session->message_count,
                $session->last_activity->diffForHumans(),
            ];

            if ($summary) {
                $rows[] = [
                    "  → {$summary}",
                    '',
                    '',
                    '',
                ];
            }
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->line('Use --session=<id> to resume a session');

        return 0;
    }
}
