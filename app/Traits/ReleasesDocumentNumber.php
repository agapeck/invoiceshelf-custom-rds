<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait that releases document numbers for reuse when soft-deleting.
 * Appends a unique suffix to the document number to avoid unique constraint conflicts.
 */
trait ReleasesDocumentNumber
{
    /**
     * Boot the trait and register the deleting event.
     */
    public static function bootReleasesDocumentNumber()
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return; // Don't modify on force delete
            }
            
            $model->releaseDocumentNumber();
        });

        static::restoring(function ($model) {
            $model->restoreDocumentNumber();
        });
    }

    /**
     * Release the document number by appending a deletion suffix.
     * This allows the original number to be reused.
     */
    protected function releaseDocumentNumber()
    {
        $numberField = $this->getDocumentNumberField();
        $originalNumber = $this->getAttribute($numberField);
        
        // Skip if already has DEL suffix
        if (strpos($originalNumber, '_DEL_') !== false) {
            return;
        }
        
        // Append deletion marker: _DEL_{id}_{timestamp}
        $suffix = '_DEL_' . $this->id . '_' . time();
        $this->setAttribute($numberField, $originalNumber . $suffix);
        
        // Save without triggering events (we're already in deleting event)
        $this->saveQuietly();
    }

    /**
     * Restore the original document number when un-deleting.
     * If the original number was reused, keeps the modified number.
     */
    protected function restoreDocumentNumber()
    {
        $numberField = $this->getDocumentNumberField();
        $currentNumber = $this->getAttribute($numberField);
        
        // Only process if it has DEL suffix
        if (strpos($currentNumber, '_DEL_') === false) {
            return;
        }
        
        // Extract original number by removing _DEL_{id}_{timestamp} suffix
        $originalNumber = preg_replace('/_DEL_\d+_\d+$/', '', $currentNumber);
        
        // Check if original number is now in use by another active record
        $conflictExists = static::where($numberField, $originalNumber)
            ->where('id', '!=', $this->id)
            ->exists();
        
        if ($conflictExists) {
            // Number was reused while this record was deleted - keep the DEL suffix
            Log::warning("Cannot restore original document number '$originalNumber' - already in use. Keeping modified number.");
            return;
        }
        
        // Safe to restore original number
        $this->setAttribute($numberField, $originalNumber);
        $this->saveQuietly();
    }

    /**
     * Get the document number field name for this model.
     */
    abstract protected function getDocumentNumberField(): string;
}

