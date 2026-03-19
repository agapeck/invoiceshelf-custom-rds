<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Schema;

class RelationNotExist implements ValidationRule
{
    public $class;

    public $relation;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct(?string $class = null, ?string $relation = null)
    {
        $this->class = $class;
        $this->relation = $relation;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $relation = $this->relation;

        $query = $this->class::query();
        if (request()->hasHeader('company')) {
            $model = $query->getModel();
            if (Schema::hasColumn($model->getTable(), 'company_id')) {
                $query->where('company_id', request()->header('company'));
            }
        }

        $record = $query->find($value);
        if (! $record) {
            return;
        }

        if ($record->$relation()->exists()) {
            $fail("Relation {$this->relation} exists.");
        }

    }
}
