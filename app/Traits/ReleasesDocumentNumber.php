<?php

namespace App\Traits;

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
     * Get the document number field name for this model.
     */
    abstract protected function getDocumentNumberField(): string;
}
