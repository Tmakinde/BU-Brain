<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('role', 20); // 'user' | 'assistant'
            $table->text('content');
            $table->string('working_app')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
