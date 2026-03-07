<?php

namespace App\Modules\Ingestion\Models;

use Illuminate\Database\Eloquent\Model;

class Chunk extends Model
{
    protected $table = 'chunks';

    public $timestamps = false;

    protected $fillable = [
        'app_name',
        'file_path',
        'class_name',
        'method_name',
        'code_type',
        'raw_text',
        'tables_referenced',
        'embedding',
    ];

    protected $casts = [
        'tables_referenced' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Set the embedding vector.
     * Accepts array of floats and converts to pgvector format.
     *
     * @param array $value Array of floats (dimensions: 1536)
     */
    public function setEmbeddingAttribute(array $value): void
    {
        // Convert PHP array to pgvector format: [0.1, 0.2, 0.3]
        $vectorString = '[' . implode(',', $value) . ']';
        $this->attributes['embedding'] = $vectorString;
    }

    /**
     * Get the embedding vector.
     * Converts pgvector format back to PHP array.
     *
     * @param string|null $value
     * @return array|null
     */
    public function getEmbeddingAttribute(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        // Strip brackets and split by comma
        $cleaned = trim($value, '[]');
        
        if (empty($cleaned)) {
            return [];
        }

        return array_map('floatval', explode(',', $cleaned));
    }

    /**
     * Scope: Search by app name.
     */
    public function scopeForApp($query, string $appName)
    {
        return $query->where('app_name', $appName);
    }

    /**
     * Scope: Search by code type.
     */
    public function scopeOfType($query, string $codeType)
    {
        return $query->where('code_type', $codeType);
    }

    /**
     * Scope: Find similar chunks using cosine similarity.
     *
     * @param array $embedding The query embedding vector
     * @param int $limit Number of results
     * @param float $threshold Minimum similarity score (0-1)
     */
    public function scopeSimilarTo($query, array $embedding, int $limit = 10, float $threshold = 0.0)
    {
        $vectorString = '[' . implode(',', $embedding) . ']';

        return $query
            ->selectRaw('*, (1 - (embedding <=> ?::vector)) as similarity', [$vectorString])
            ->whereRaw('(1 - (embedding <=> ?::vector)) >= ?', [$vectorString, $threshold])
            ->orderByRaw('embedding <=> ?::vector', [$vectorString])
            ->limit($limit);
    }
}
