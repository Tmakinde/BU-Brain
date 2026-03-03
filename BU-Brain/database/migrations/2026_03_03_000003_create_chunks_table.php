<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->string('app_name');
            $table->text('file_path');
            $table->string('class_name')->nullable();
            $table->string('method_name')->nullable();
            $table->string('code_type', 50); // 'method' | 'class' | 'route' | 'migration' | 'config'
            $table->text('raw_text');
            $table->jsonb('tables_referenced')->default('[]');
            $table->timestamp('created_at')->useCurrent();

            $table->index('app_name');
            $table->index('code_type');
        });

        // Add vector column using raw SQL (pgvector)
        DB::statement('ALTER TABLE chunks ADD COLUMN embedding vector(768)');
        
        // Create IVFFlat index for fast similarity search
        DB::statement('CREATE INDEX idx_chunks_embedding ON chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
