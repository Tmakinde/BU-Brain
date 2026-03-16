# BU-Brain 🧠 (STILL IN PROGRESS)

**AI-Powered Semantic Search for Internal Codebases**

BU-Brain is a Retrieval-Augmented Generation (RAG) system that enables natural language search and conversational Q&A across multiple internal applications. Ask questions like "What is XYZ servive doing in this application called BCD?" and get context-aware answers with code references.

---

## ✨ Features

- 🔍 **Multi-Codebase Search** - Index and search across multiple applications simultaneously
- 🤖 **Conversational AI** - Ask follow-up questions with context awareness
- 🎯 **Vector Similarity** - Semantic search using pgvector and embeddings
- 📊 **AST-Based Parsing** - Accurate PHP code analysis (routes, migrations, classes, methods)
- 🔌 **Pluggable Providers** - Swap LLM/embedding providers (Ollama, OpenAI, Claude)
- 🌐 **API & CLI** - Web API endpoints + interactive terminal chat
- 📝 **Structured Responses** - Confidence levels, sources, cross-app connections
- 🎨 **Customizable Prompts** - Extensible prompt system (code explanation, architecture, custom)

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     BU-Brain System                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────┐   ┌──────────────┐   ┌───────────────┐  │
│  │  Ingestion  │──▶│   Embedding  │──▶│  PostgreSQL   │  │
│  │   Module    │   │    Module    │   │  + pgvector   │  │
│  └─────────────┘   └──────────────┘   └───────────────┘  │
│         │                                     │            │
│         ├─ AST Parsing                       │            │
│         ├─ Code Chunking                     ▼            │
│         └─ Metadata Extraction    ┌──────────────────┐   │
│                                     │  Vector Search   │   │
│  ┌─────────────┐                   │   (Similarity)   │   │
│  │    Query    │──────────────────▶└──────────────────┘   │
│  │   Module    │                            │              │
│  └─────────────┘                            │              │
│         │                                    ▼              │
│         │                        ┌──────────────────┐      │
│         │                        │ Context Builder  │      │
│         │                        └──────────────────┘      │
│         │                                    │              │
│         ▼                                    ▼              │
│  ┌──────────────────────────────────────────────────────┐ │
│  │              Agent Module                            │ │
│  │  ┌──────────────┐  ┌──────────────┐  ┌───────────┐ │ │
│  │  │PromptBuilder │─▶│ LLM Provider │─▶│Conversation│ │ │
│  │  └──────────────┘  └──────────────┘  └───────────┘ │ │
│  └──────────────────────────────────────────────────────┘ │
│                          │                                 │
│                          ▼                                 │
│              ┌───────────────────────┐                    │
│              │  API    │    CLI      │                    │
│              └───────────────────────┘                    │
└─────────────────────────────────────────────────────────────┘
```

### Module Structure

```
app/Modules/
├── Registry/          # App registry & metadata
├── Ingestion/         # Code parsing, chunking, indexing
│   ├── Services/      # LocalFileReader, CodeChunker, etc.
│   ├── Chunkers/      # PhpChunker, JsChunker, SqlChunker
│   ├── Visitors/      # AST visitors for PHP analysis
│   └── Jobs/          # IndexAppJob (queue processing)
├── Embedding/         # Vector embedding generation
│   ├── Contracts/     # EmbeddingProvider interface
│   └── Providers/     # OllamaEmbeddingProvider
├── Query/             # Search & retrieval
│   └── Services/      # QueryService, RetrievalService, ContextBuilder
└── Agent/             # Conversational AI
    ├── Contracts/     # LLMProvider interface
    ├── Providers/     # OllamaLLMProvider
    ├── Prompts/       # BasePrompt, CodeExplanationPrompt, etc.
    ├── Services/      # AgentService, ConversationService, PromptBuilder
    ├── Controllers/   # ChatController (API)
    └── Models/        # Conversation (history storage)
```

---

## 🚀 Quick Start

### Prerequisites

- PHP 8.2+
- PostgreSQL 16+ with pgvector extension
- Docker & Docker Compose
- Composer

### Installation

1. **Clone & Install Dependencies**
```bash
git clone <repo-url> bu-brain
cd bu-brain/BU-Brain
composer install
```

2. **Environment Setup**
```bash
cp .env.example .env
php artisan key:generate
```

3. **Configure Database** (`.env`)
```env
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=bu_brain
DB_USERNAME=postgres
DB_PASSWORD=secret

# Ollama Configuration
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_CHAT_MODEL=qwen2.5-coder:1.5b
```

4. **Start Services**
```bash
docker-compose up -d  # PostgreSQL + Ollama
```

5. **Run Migrations**
```bash
php artisan migrate
```

6. **Configure Apps** (`database/seeders/AppRegistrySeeder.php`)
```php
AppRegistry::create([
    'name' => 'appname',
    'source_path' => '/path/to/app',
    'stack' => 'yii2',
    'file_filter_rules' => [
        'include' => ['*.php', '*.js', '*.sql'],
        'exclude' => ['vendor/', 'node_modules/', 'tests/'],
    ],
]);
```

7. **Seed & Index**
```bash
php artisan db:seed --class=AppRegistrySeeder
php artisan bu-brain:index appname
php artisan queue:work  # Process indexing job
```

---

## 📖 Usage

### CLI Commands

#### Index an Application
```bash
# Index a specific app
php artisan bu-brain:index appname

# View indexing stats
php artisan bu-brain:stats

# View stats for specific app
php artisan bu-brain:stats --app=appname
```

#### Search Code
```bash
# Basic search
php artisan bu-brain:search "What is CSC" --app=appname

# Adjust similarity threshold
php artisan bu-brain:search "authentication flow" --min-similarity=0.6

# Limit results
php artisan bu-brain:search "database query" --limit=5

# JSON output
php artisan bu-brain:search "user model" --format=json
```

#### Interactive Chat
```bash
# Start interactive chat
php artisan bu-brain:chat --app=appname

# Resume previous session
php artisan bu-brain:chat --session=<session-id>

# View recent sessions
php artisan bu-brain:chat --sessions

# View conversation history
php artisan bu-brain:chat --session=<session-id> --history
```

**Chat Commands:**
- `/help` - Show available commands
- `/history` - View conversation history
- `/clear` - Clear conversation and start new session
- `/app` - Change app context
- `/exit` - Exit chat

**Example Chat Session:**
```
╔════════════════════════════════════════════════════════════╗
║              Welcome to BU-Brain Chat                      ║
╚════════════════════════════════════════════════════════════╝

Session ID: 550e8400-e29b-41d4-a716-446655440000
App Context: appname

Commands:
  /help     - Show available commands
  /history  - Show conversation history
  /clear    - Clear conversation history
  /app      - Change app context
  /exit     - Exit chat

You: What is CSC in appname?

Thinking...

Assistant:
**What It Does**
CSC is the Customer Service Center module...

**Which App Owns It**
appname → app/services/CSCService.php → validateToken()

**Confidence**
High — Found direct implementation with clear naming

**Sources**
- appname → CSCService.php → validateToken()
- appname → models/CSC.php → CSC model class

Used 3 code chunks
```

### API Endpoints

#### Send Chat Message
```bash
curl -X POST http://localhost:8000/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "message": "What is CSC in appname?",
    "app_name": "appname",
    "session_id": "optional-session-uuid"
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "response": "**What It Does**\nCSC is...",
    "session_id": "550e8400-e29b-41d4-a716-446655440000",
    "chunks_used": 3,
    "app_name": "appname"
  }
}
```

#### Get Sessions
```bash
curl http://localhost:8000/api/chat/sessions
```

#### Get Session History
```bash
curl http://localhost:8000/api/chat/sessions/{session-id}
```

#### Delete Session
```bash
curl -X DELETE http://localhost:8000/api/chat/sessions/{session-id}
```

#### Health Check
```bash
curl http://localhost:8000/api/chat/health
```

---

## ⚙️ Configuration

### LLM Configuration (`config/llm.php`)

```php
return [
    'default' => env('LLM_PROVIDER', 'ollama'),
    
    'providers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_CHAT_MODEL', 'qwen2.5-coder:1.5b'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
            'options' => [
                'temperature' => 0.7,
                'num_ctx' => 8192,
            ],
        ],
    ],
    
    'context' => [
        'max_chunks' => env('LLM_MAX_CHUNKS', 10),
        'min_similarity' => env('LLM_MIN_SIMILARITY', 0.5),
        'conversation_history_limit' => env('LLM_CONVERSATION_HISTORY', 10),
    ],
];
```

### Embedding Configuration (`config/embedding.php`)

```php
return [
    'default' => env('EMBEDDING_PROVIDER', 'ollama'),
    
    'providers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
            'timeout' => env('OLLAMA_TIMEOUT', 60),
        ],
    ],
];
```

---

## 🎨 Custom Prompts

Extend the prompt system by creating custom prompt classes:

```php
<?php

namespace App\Modules\Agent\Prompts;

class SecurityAuditPrompt extends BasePrompt
{
    public function getSystemPrompt(): string
    {
        return 'You are a security auditor. Find vulnerabilities...';
    }

    public function formatUserMessage(string $question, string $codeContext): string
}    {
        return "AUDIT: {$question}\n\nCODE: {$codeContext}";
    }

    public function getName(): string
    {
        return 'security_audit';
    }
```

Register in `PromptFactory` and use:
```bash
php artisan bu-brain:chat --app=appname --options='{"prompt":"security_audit"}'
```

See [app/Modules/Agent/Prompts/README.md](app/Modules/Agent/Prompts/README.md) for details.

---

## 🧪 Testing

### Test Vector Search
```bash
php artisan bu-brain:search "user authentication" --app=appname --limit=5
```

### Test Conversation
```bash
php artisan bu-brain:chat --app=appname
> What handles user login?
> How does it validate passwords?
> /history
```

### Test API
```bash
# Health check
curl http://localhost:8000/api/chat/health

# Send question
curl -X POST http://localhost:8000/api/chat \
  -H "Content-Type: application/json" \
  -d '{"message": "What is CSC?", "app_name": "appname"}'
```

---

## 📊 Database Schema

### `app_registry` Table
Stores registered applications and their indexing configuration.

### `chunks` Table  
Stores code chunks with vector embeddings (1536 dimensions, zero-padded from 768).

### `conversations` Table
Stores chat history grouped by session_id.

---

## 🔧 Development

### Adding a New Chunker
Create class in `app/Modules/Ingestion/Chunkers/` implementing `ChunkerInterface`.

### Adding a New LLM Provider
Implement `LLMProvider` interface and register in `AppServiceProvider`.

---

## 🐛 Troubleshooting  

**Ollama Issues:**
```bash
curl http://localhost:11434/api/tags
ollama pull nomic-embed-text
```

**pgvector Extension:**
```sql
CREATE EXTENSION vector;
```

**Memory Issues:**
Use smaller models like `qwen2.5-coder:1.5b` (~1GB) or `phi`.

---

## 📈 Performance Tips

1. **Batch Indexing** - Use queue workers  
2. **IVFFlat Index** - Tune `lists` parameter
3. **Chunk Size** - Optimize for your codebase
4. **Context Window** - Adjust `max_chunks`
5. **Similarity** - Increase `min_similarity` threshold

---

## 🎯 Roadmap

### ✅ Phase 1 (Complete)
- Multi-app indexing  
- Vector similarity search
- Conversational agent
- CLI & API interfaces

### 🔄 Phase 2 (Planned)
- Hybrid search (BM25 + vector)
- Reranking service
- Intent classification  
- Query rewriting

### 🚀 Phase 3 (Future)
- Code symbol graph
- Multi-step tool calling
- Cross-app dependencies
- GitLab API integration

---

## 📝 License

Proprietary software for internal use only.

---

## 🤝 Contributing

Fork → Branch → Test → Pull Request

---

Built with ❤️ using Laravel, pgvector, and Ollama
### DEVELOPMENT STILL IN PROGRESS