<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExchangeRateLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExchangeRateLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ExchangeRateLog::class;

    public function configure(): static
    {
        return $this->afterCreating(function (ExchangeRateLog $exchangeRateLog) {
            if (! CompanySetting::query()
                ->where('company_id', $exchangeRateLog->company_id)
                ->where('option', 'currency')
                ->exists()) {
                CompanySetting::setSettings([
                    'currency' => $exchangeRateLog->currency_id,
                ], $exchangeRateLog->company_id);
            }
        });
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $user = User::query()->first();
        $companyId = $user?->companies()->first()?->id ?? Company::query()->value('id');
        $companyCurrencyId = $companyId ? CompanySetting::getSetting('currency', $companyId) : null;
        $fallbackCurrencyId = Currency::query()->first()?->id;
        $currencyId = $companyCurrencyId ?: $fallbackCurrencyId;
        $baseCurrencyId = $currencyId
            ? Currency::query()->whereKeyNot($currencyId)->value('id') ?? $currencyId
            : null;

        return [
            'company_id' => $companyId ?? Company::factory(),
            'base_currency_id' => $baseCurrencyId ?? Currency::factory(),
            'currency_id' => $currencyId ?? Currency::factory(),
            'exchange_rate' => $this->faker->randomDigitNotNull(),
        ];
    }
}
