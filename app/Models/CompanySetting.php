<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySetting extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'option', 'value'];

    protected static $settingsCache = [];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeWhereCompany($query, $company_id)
    {
        $query->where('company_id', $company_id);
    }

    public static function setSettings($settings, $company_id)
    {
        foreach ($settings as $key => $value) {
            self::updateOrCreate(
                [
                    'option' => $key,
                    'company_id' => $company_id,
                ],
                [
                    'option' => $key,
                    'company_id' => $company_id,
                    'value' => $value,
                ]
            );
        }

        static::flushCompanyCache((int) $company_id);
    }

    public static function getAllSettings($company_id)
    {
        return static::whereCompany($company_id)->get()->mapWithKeys(function ($item) {
            return [$item['option'] => $item['value']];
        });
    }

    public static function getSettings($settings, $company_id)
    {
        return static::whereIn('option', $settings)->whereCompany($company_id)
            ->get()->mapWithKeys(function ($item) {
                return [$item['option'] => $item['value']];
            });
    }

    public static function getSetting($key, $company_id)
    {
        $cacheKey = $company_id . '.' . $key;

        if (isset(static::$settingsCache[$cacheKey])) {
            return static::$settingsCache[$cacheKey];
        }

        $setting = static::whereOption($key)->whereCompany($company_id)->first();

        $value = $setting ? $setting->value : null;

        static::$settingsCache[$cacheKey] = $value;

        return $value;
    }

    private static function flushCompanyCache(int $companyId): void
    {
        foreach (array_keys(static::$settingsCache) as $key) {
            if (str_starts_with((string) $key, $companyId.'.')) {
                unset(static::$settingsCache[$key]);
            }
        }
    }
}
