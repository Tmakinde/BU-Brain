# Prompt System Documentation

## Overview
The prompt system uses an abstract class pattern to make prompts reusable, testable, and easy to customize.

## Architecture

```
BasePrompt (abstract)
├── CodeExplanationPrompt (default)
├── ArchitecturePrompt
└── [Your custom prompt here]
```

## Usage

### 1. Using in ChatCommand or API

```php
// Use default prompt (CodeExplanationPrompt)
$agentService->ask('What is CSC?', $sessionId, 'appname');

// Use specific prompt by name
$agentService->ask('What is CSC?', $sessionId, 'appname', [
    'prompt' => 'architecture'
]);
```

### 2. Creating a Custom Prompt

```php
<?php

namespace App\Modules\Agent\Prompts;

class SecurityAuditPrompt extends BasePrompt
{
    public function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are a security auditor reviewing code for vulnerabilities.
Focus on: SQL injection, XSS, authentication flaws, etc.
SYSTEM;
    }

    public function formatUserMessage(string $question, string $codeContext): string
    {
        return "SECURITY AUDIT REQUEST:\n{$question}\n\nCODE:\n{$codeContext}";
    }

    public function getName(): string
    {
        return 'security_audit';
    }
}
```

### 3. Register the Prompt

In `PromptFactory.php`:
```php
public function __construct()
{
    $this->registerPrompt(new CodeExplanationPrompt());
    $this->registerPrompt(new ArchitecturePrompt());
    $this->registerPrompt(new SecurityAuditPrompt()); // Add this
}
```

### 4. Use Your New Prompt

```php
php artisan bu-brain:chat --app=appname

You: Check login.php for security issues (use /prompt security_audit)
```

## Benefits

✅ **Clean Code** - Prompts are isolated in their own classes
✅ **Reusable** - Same prompt can be used across CLI, API, tests
✅ **Testable** - Easy to unit test prompt formatting
✅ **Extensible** - Add new prompts without touching core code
✅ **Type-Safe** - IDE autocomplete and type checking

## Available Prompts

- `code_explanation` (default) - Structured code Q&A
- `architecture` - High-level system design analysis

## API Example

```bash
curl -X POST http://localhost:8000/api/chat \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Explain the authentication flow",
    "app_name": "appname",
    "options": {
      "prompt": "architecture"
    }
  }'
```
