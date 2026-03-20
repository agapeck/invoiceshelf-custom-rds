<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Silber\Bouncer\BouncerFacade;

class CustomerPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @return mixed
     */
    public function viewAny(User $user): bool
    {
        if (BouncerFacade::can('view-customer', Customer::class)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view the model.
     *
     * @return mixed
     */
    public function view(User $user, Customer $customer): bool
    {
        if (BouncerFacade::can('view-customer', $customer) && $this->belongsToActiveCompany($user, $customer->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     *
     * @return mixed
     */
    public function create(User $user): bool
    {
        if (BouncerFacade::can('create-customer', Customer::class)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     *
     * @return mixed
     */
    public function update(User $user, Customer $customer): bool
    {
        if (BouncerFacade::can('edit-customer', $customer) && $this->belongsToActiveCompany($user, $customer->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * @return mixed
     */
    public function delete(User $user, Customer $customer): bool
    {
        if (BouncerFacade::can('delete-customer', $customer) && $this->belongsToActiveCompany($user, $customer->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     *
     * @return mixed
     */
    public function restore(User $user, Customer $customer): bool
    {
        if (BouncerFacade::can('delete-customer', $customer) && $this->belongsToActiveCompany($user, $customer->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     *
     * @return mixed
     */
    public function forceDelete(User $user, Customer $customer): bool
    {
        if (BouncerFacade::can('delete-customer', $customer) && $this->belongsToActiveCompany($user, $customer->company_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete models.
     *
     * @return mixed
     */
    public function deleteMultiple(User $user)
    {
        if (BouncerFacade::can('delete-customer', Customer::class)) {
            return true;
        }

        return false;
    }

    private function belongsToActiveCompany(User $user, int $recordCompanyId): bool
    {
        $activeCompanyId = (int) (request()->header('company') ?: request()->query('company'));

        if (! $activeCompanyId) {
            return false;
        }

        return $activeCompanyId === (int) $recordCompanyId && $user->hasCompany($activeCompanyId);
    }
}
