<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Vinkla\Hashids\Facades\Hashids;

trait GeneratesHashTrait
{
    /**
     * Boot the trait.
     */
    protected static function bootGeneratesHashTrait()
    {
        static::created(function ($model) {
            if (!$model->ensureUniqueHash()) {
                Log::warning('Failed to generate unique_hash for new ' . static::class . ' with id ' . $model->id);
            }
        });
    }

    /**
     * Ensure the model has a unique hash.
     *
     * @return bool
     */
    public function ensureUniqueHash()
    {
        if ($this->unique_hash) {
            return true;
        }

        try {
            $this->unique_hash = Hashids::connection(static::class)->encode($this->id);
            return $this->saveQuietly();
        } catch (\Throwable $e) {
            Log::error('Failed to generate unique_hash for ' . static::class, [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Regenerate unique_hash for models that are missing it.
     *
     * @return array
     */
    public static function regenerateMissingHashes()
    {
        $results = ['success' => 0, 'failed' => 0];
        
        // Find records with null or empty unique_hash
        // chunk() is used to handle large datasets efficiently
        // Use chunkById to avoid skipping records when result set changes during iteration
        static::whereNull('unique_hash')->orWhere('unique_hash', '')->chunkById(100, function ($models) use (&$results) {
            foreach ($models as $model) {
                if ($model->ensureUniqueHash()) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            }
        });
        
        return $results;
    }
}
