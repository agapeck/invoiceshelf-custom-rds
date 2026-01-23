<?php

namespace App\Traits;

use Illuminate\Database\QueryException;
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
                Log::error('Failed to generate unique_hash for new ' . static::class . ' with id ' . $model->id, [
                    'model_class' => static::class,
                    'model_id' => $model->id,
                ]);
            }
        });
    }

    /**
     * Ensure the model has a unique hash.
     * 
     * This method generates a unique hash using Hashids and saves it to the model.
     * It includes retry logic for handling rare collision scenarios and explicit
     * error handling for database constraint violations.
     *
     * @param int $maxRetries Maximum number of retry attempts for collision handling
     * @return bool True if hash was successfully generated and saved
     */
    public function ensureUniqueHash(int $maxRetries = 3): bool
    {
        if ($this->unique_hash) {
            return true;
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                // Generate hash from model ID
                $hash = Hashids::connection(static::class)->encode($this->id);
                
                // Verify the hash can be decoded back to the correct ID
                $decoded = Hashids::connection(static::class)->decode($hash);
                if (empty($decoded) || $decoded[0] !== $this->id) {
                    Log::error('Hash decode verification failed for ' . static::class, [
                        'id' => $this->id,
                        'hash' => $hash,
                        'decoded' => $decoded,
                        'attempt' => $attempt,
                    ]);
                    $lastError = 'Hash decode verification failed';
                    continue;
                }
                
                $this->unique_hash = $hash;
                
                // Use save() instead of saveQuietly() to get proper exception handling
                // We catch the exception ourselves to handle it appropriately
                $saved = $this->saveQuietly();
                
                if ($saved) {
                    return true;
                }
                
                // saveQuietly returned false - try to understand why
                Log::warning('saveQuietly returned false for ' . static::class, [
                    'id' => $this->id,
                    'hash' => $hash,
                    'attempt' => $attempt,
                ]);
                
                // Reset hash for retry
                $this->unique_hash = null;
                $lastError = 'saveQuietly returned false';
                
            } catch (QueryException $e) {
                // Handle duplicate key constraint violations
                if ($this->isDuplicateKeyException($e)) {
                    Log::warning('Hash collision detected for ' . static::class, [
                        'id' => $this->id,
                        'hash' => $this->unique_hash ?? 'unknown',
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Reset hash for retry
                    $this->unique_hash = null;
                    $lastError = 'Duplicate key constraint violation';
                    
                    // Wait a tiny bit before retry to allow any concurrent operations to complete
                    usleep(10000 * $attempt); // 10ms * attempt number
                    continue;
                }
                
                // Other database errors - log and fail
                Log::error('Database error generating unique_hash for ' . static::class, [
                    'id' => $this->id,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);
                return false;
                
            } catch (\Throwable $e) {
                Log::error('Failed to generate unique_hash for ' . static::class, [
                    'id' => $this->id,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
                $lastError = $e->getMessage();
            }
        }
        
        // All retries exhausted
        Log::error('All retries exhausted generating unique_hash for ' . static::class, [
            'id' => $this->id,
            'max_retries' => $maxRetries,
            'last_error' => $lastError,
        ]);
        
        return false;
    }

    /**
     * Check if a QueryException is a duplicate key violation.
     *
     * @param QueryException $e
     * @return bool
     */
    private function isDuplicateKeyException(QueryException $e): bool
    {
        // MySQL error code 1062 = Duplicate entry for key
        // PostgreSQL error code 23505 = unique_violation
        // SQLite error code 19 = SQLITE_CONSTRAINT
        $code = $e->errorInfo[1] ?? $e->getCode();
        
        return in_array($code, [1062, 23505, 19]) 
            || str_contains($e->getMessage(), 'Duplicate entry')
            || str_contains($e->getMessage(), 'unique constraint')
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Regenerate unique_hash for models that are missing it.
     *
     * @return array
     */
    public static function regenerateMissingHashes(): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        // Find records with null or empty unique_hash
        // Use chunkById to avoid skipping records when result set changes during iteration
        static::whereNull('unique_hash')
            ->orWhere('unique_hash', '')
            ->chunkById(100, function ($models) use (&$results) {
                foreach ($models as $model) {
                    if ($model->ensureUniqueHash()) {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'id' => $model->id,
                            'class' => static::class,
                        ];
                    }
                }
            });
        
        return $results;
    }

    /**
     * Verify that the model's unique_hash can be decoded correctly.
     *
     * @return bool
     */
    public function verifyHashIntegrity(): bool
    {
        if (!$this->unique_hash) {
            return false;
        }

        try {
            $decoded = Hashids::connection(static::class)->decode($this->unique_hash);
            return !empty($decoded) && $decoded[0] === $this->id;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
