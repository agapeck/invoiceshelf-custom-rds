<?php

namespace App\Models;

use App\Http\Requests\UserRequest;
use App\Notifications\MailResetPasswordNotification;
use App\Traits\HasCustomFieldsTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\Database\HasRolesAndAbilities;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens;
    use HasCustomFieldsTrait;
    use HasFactory;
    use HasRolesAndAbilities;
    use InteractsWithMedia;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [
        'id',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $with = [
        'currency',
    ];

    protected $appends = [
        'formattedCreatedAt',
        'avatar',
    ];

    /**
     * Find the user instance for the given username.
     *
     * @param  string  $username
     * @return \App\User
     */
    public function findForPassport($username)
    {
        return $this->where('email', $username)->first();
    }

    public function setPasswordAttribute($value)
    {
        if ($value != null) {
            $this->attributes['password'] = bcrypt($value);
        }
    }

    public function isSuperAdminOrAdmin()
    {
        return ($this->role == 'super admin') || ($this->role == 'admin');
    }

    public static function login($request)
    {
        $remember = $request->remember;
        $email = $request->email;
        $password = $request->password;

        return \Auth::attempt(['email' => $email, 'password' => $password], $remember);
    }

    public function getFormattedCreatedAtAttribute($value)
    {
        $company_id = (CompanySetting::where('company_id', request()->header('company'))->exists())
            ? request()->header('company')
            : $this->companies()->first()->id;
        $dateFormat = CompanySetting::getSetting('carbon_date_format', $company_id);

        return Carbon::parse($this->created_at)->format($dateFormat);
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class, 'creator_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'creator_id');
    }

    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class, 'creator_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'creator_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_company', 'user_id', 'company_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'creator_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'creator_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'creator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'creator_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class, 'user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('type', Address::BILLING_TYPE);
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('type', Address::SHIPPING_TYPE);
    }

    /**
     * Override the mail body for reset password notification mail.
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new MailResetPasswordNotification($token));
    }

    public function scopeWhereOrder($query, $orderByField, $orderBy)
    {
        $allowed = ['id', 'name', 'email', 'phone', 'role', 'created_at', 'updated_at'];
        $field = in_array($orderByField, $allowed) ? $orderByField : 'name';
        $direction = in_array(strtolower($orderBy), ['asc', 'desc']) ? $orderBy : 'asc';
        $query->orderBy($field, $direction);
    }

    public function scopeWhereSearch($query, $search)
    {
        foreach (explode(' ', $search) as $term) {
            $query->where(function ($query) use ($term) {
                $query->where('name', 'LIKE', '%'.$term.'%')
                    ->orWhere('email', 'LIKE', '%'.$term.'%')
                    ->orWhere('phone', 'LIKE', '%'.$term.'%');
            });
        }
    }

    public function scopeWhereContactName($query, $contactName)
    {
        return $query->where('contact_name', 'LIKE', '%'.$contactName.'%');
    }

    public function scopeWhereDisplayName($query, $displayName)
    {
        return $query->where('name', 'LIKE', '%'.$displayName.'%');
    }

    public function scopeWherePhone($query, $phone)
    {
        return $query->where('phone', 'LIKE', '%'.$phone.'%');
    }

    public function scopeWhereEmail($query, $email)
    {
        return $query->where('email', 'LIKE', '%'.$email.'%');
    }

    public function scopePaginateData($query, $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }

    public function scopeApplyFilters($query, array $filters)
    {
        $filters = collect($filters);

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        if ($filters->get('display_name')) {
            $query->whereDisplayName($filters->get('display_name'));
        }

        if ($filters->get('email')) {
            $query->whereEmail($filters->get('email'));
        }

        if ($filters->get('phone')) {
            $query->wherePhone($filters->get('phone'));
        }

        if ($filters->get('role')) {
            $query->whereIs($filters->get('role'));
        }

        if ($filters->get('company_id')) {
            $query->whereHas('companies', function ($q) use ($filters) {
                $q->where('company_id', $filters->get('company_id'));
            });
        }

        if ($filters->get('orderByField') || $filters->get('orderBy')) {
            $field = $filters->get('orderByField') ? $filters->get('orderByField') : 'name';
            $orderBy = $filters->get('orderBy') ? $filters->get('orderBy') : 'asc';
            $query->whereOrder($field, $orderBy);
        }
    }

    public function scopeWhereSuperAdmin($query)
    {
        $query->where('role', 'super admin');
    }

    public function scopeApplyInvoiceFilters($query, array $filters)
    {
        $filters = collect($filters);

        if ($filters->get('from_date') && $filters->get('to_date')) {
            $start = Carbon::createFromFormat('Y-m-d', $filters->get('from_date'));
            $end = Carbon::createFromFormat('Y-m-d', $filters->get('to_date'));
            $query->invoicesBetween($start, $end);
        }
    }

    public function scopeInvoicesBetween($query, $start, $end)
    {
        $query->whereHas('invoices', function ($query) use ($start, $end) {
            $query->whereBetween(
                'invoice_date',
                [$start->format('Y-m-d'), $end->format('Y-m-d')]
            );
        });
    }

    public function getAvatarAttribute()
    {
        $avatar = $this->getMedia('admin_avatar')->first();

        if ($avatar) {
            return asset($avatar->getUrl());
        }

        return 0;
    }

    public function setSettings($settings)
    {
        foreach ($settings as $key => $value) {
            $this->settings()->updateOrCreate(
                [
                    'key' => $key,
                ],
                [
                    'key' => $key,
                    'value' => $value,
                ]
            );
        }
    }

    public function hasCompany($company_id)
    {
        $companies = $this->companies()->pluck('company_id')->toArray();

        return in_array($company_id, $companies);
    }

    public function getAllSettings()
    {
        return $this->settings()->get()->mapWithKeys(function ($item) {
            return [$item['key'] => $item['value']];
        });
    }

    public function getSettings($settings)
    {
        return $this->settings()->whereIn('key', $settings)->get()->mapWithKeys(function ($item) {
            return [$item['key'] => $item['value']];
        });
    }

    public function isOwner()
    {
        if (Schema::hasColumn('companies', 'owner_id')) {
            $company = Company::find(request()->header('company'));

            if ($company && $this->id == $company->owner_id) {
                return true;
            }
        } else {
            return $this->role == 'super admin' || $this->role == 'admin';
        }

        return false;
    }

    public static function createFromRequest(UserRequest $request)
    {
        $user = self::create($request->getUserPayload());

        $user->setSettings([
            'language' => CompanySetting::getSetting('language', $request->header('company')),
        ]);

        $companies = self::sanitizeCompanyAssignments(
            collect($request->companies),
            $request->user(),
            (int) $request->header('company')
        );

        if ($companies->isEmpty()) {
            throw ValidationException::withMessages([
                'companies' => 'At least one valid company assignment is required.',
            ]);
        }

        $user->companies()->sync($companies->pluck('id'));

        foreach ($companies as $company) {
            BouncerFacade::scope()->to($company['id']);

            BouncerFacade::sync($user)->roles([$company['role']]);
        }

        return $user;
    }

    public function updateFromRequest(UserRequest $request)
    {
        $this->update($request->getUserPayload());

        $activeCompanyId = (int) $request->header('company');
        $actor = $request->user();
        $companies = self::sanitizeCompanyAssignments(
            collect($request->companies),
            $actor,
            $activeCompanyId
        );

        if ($companies->isEmpty()) {
            throw ValidationException::withMessages([
                'companies' => 'At least one valid company assignment is required.',
            ]);
        }

        if ($actor && ! $actor->isSuperAdminOrAdmin()) {
            $existingCompanyIds = $this->companies()->pluck('companies.id');
            $newCompanyIds = $existingCompanyIds
                ->reject(fn ($id) => (int) $id === $activeCompanyId)
                ->merge($companies->pluck('id'))
                ->unique()
                ->values();

            $this->companies()->sync($newCompanyIds);
        } else {
            $this->companies()->sync($companies->pluck('id'));
        }

        foreach ($companies as $company) {
            BouncerFacade::scope()->to($company['id']);

            BouncerFacade::sync($this)->roles([$company['role']]);
        }

        return $this;
    }

    private static function sanitizeCompanyAssignments(Collection $companies, ?User $actor, int $activeCompanyId): Collection
    {
        if (! $actor || $actor->isSuperAdminOrAdmin()) {
            return $companies->filter(fn ($company) => isset($company['id'], $company['role']))->values();
        }

        if (! $activeCompanyId || ! $actor->hasCompany($activeCompanyId)) {
            return collect();
        }

        return $companies
            ->filter(function ($company) use ($activeCompanyId) {
                return isset($company['id'], $company['role']) && (int) $company['id'] === $activeCompanyId;
            })
            ->values();
    }

    public function checkAccess($data)
    {
        if ($this->isOwner()) {
            return true;
        }

        if ((! $data->data['owner_only']) && empty($data->data['ability'])) {
            return true;
        }

        if ((! $data->data['owner_only']) && (! empty($data->data['ability'])) && (! empty($data->data['model'])) && $this->can($data->data['ability'], $data->data['model'])) {
            return true;
        }

        if ((! $data->data['owner_only']) && $this->can($data->data['ability'])) {
            return true;
        }

        return false;
    }

    public static function deleteUsers($ids, $companyId = null)
    {
        return DB::transaction(function () use ($ids, $companyId) {
            $query = self::query();
            if ($companyId) {
                $query->whereHas('companies', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                });
            }

            $users = $query->whereIn('id', $ids)->get();

            $activeCompanyId = $companyId ? (int) $companyId : (int) request()->header('company');
            $actorId = (int) optional(request()->user())->id;
            $ownerId = null;
            $ownedUserIds = Company::query()
                ->whereIn('owner_id', $users->pluck('id'))
                ->pluck('owner_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($activeCompanyId && Schema::hasColumn('companies', 'owner_id')) {
                $ownerId = (int) optional(Company::find($activeCompanyId))->owner_id;
            }

            foreach ($users as $user) {
                if ($actorId && (int) $user->id === $actorId) {
                    throw ValidationException::withMessages([
                        'users' => 'You cannot delete your own account.',
                    ]);
                }

                if ($ownerId && (int) $user->id === $ownerId) {
                    throw ValidationException::withMessages([
                        'users' => 'You cannot delete the active company owner.',
                    ]);
                }

                if (in_array((int) $user->id, $ownedUserIds, true)) {
                    throw ValidationException::withMessages([
                        'users' => 'You cannot delete a user who owns a company.',
                    ]);
                }

                self::nullifyCreatorRelation($user->invoices());
                self::nullifyCreatorRelation($user->estimates());
                self::nullifyCreatorRelation($user->customers());
                self::nullifyCreatorRelation($user->recurringInvoices());
                self::nullifyCreatorRelation($user->expenses());
                self::nullifyCreatorRelation($user->payments());
                self::nullifyCreatorRelation($user->items());

                if ($user->settings()->exists()) {
                    $user->settings()->delete();
                }

                $user->delete();
            }

            return true;
        });
    }

    private static function nullifyCreatorRelation(HasMany $relation): void
    {
        $related = $relation->getRelated();

        if (in_array(SoftDeletes::class, class_uses_recursive($related), true)) {
            $relation->withTrashed()->update(['creator_id' => null]);

            return;
        }

        $relation->update(['creator_id' => null]);
    }
}
