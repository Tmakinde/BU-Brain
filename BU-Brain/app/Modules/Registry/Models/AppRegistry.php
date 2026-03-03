<?php

namespace App\Modules\Registry\Models;

use Illuminate\Database\Eloquent\Model;

class AppRegistry extends Model
{
    protected $table = 'app_registry';

    protected $fillable = [
        'name',
        'description',
        'source_path',
        'stack',
        'talks_to',
        'file_filter_rules',
        'last_indexed_at',
    ];

    protected $casts = [
        'talks_to' => 'array',
        'file_filter_rules' => 'array',
        'last_indexed_at' => 'datetime',
    ];

    /**
     * Get the file extensions to include for this app.
     */
    public function getExtensions(): array
    {
        return $this->file_filter_rules['extensions'] ?? [];
    }

    /**
     * Get the paths to include for this app.
     */
    public function getIncludePaths(): array
    {
        return $this->file_filter_rules['include_paths'] ?? [];
    }

    /**
     * Get the paths to exclude for this app.
     */
    public function getExcludePaths(): array
    {
        return $this->file_filter_rules['exclude_paths'] ?? [];
    }

    /**
     * Get the files to exclude for this app.
     */
    public function getExcludeFiles(): array
    {
        return $this->file_filter_rules['exclude_files'] ?? [];
    }
}
