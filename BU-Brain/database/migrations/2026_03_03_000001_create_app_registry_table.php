<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_registry', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description');
            $table->string('source_path', 500);
            $table->string('stack', 50); // 'laravel' | 'node' | 'python'
            $table->jsonb('talks_to')->default('[]');
            $table->jsonb('file_filter_rules');
            $table->timestamp('last_indexed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_registry');
    }
};
