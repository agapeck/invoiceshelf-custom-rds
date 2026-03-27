<?php

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\ExchangeRateLog;
use App\Models\Expense;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('an exchange rate log belongs to company', function () {
    $exchangeRateLog = ExchangeRateLog::factory()->forCompany()->create();

    $this->assertTrue($exchangeRateLog->company->exists());
});

test('add exchange rate log', function () {
    $expense = Expense::factory()->create();
    $response = ExchangeRateLog::addExchangeRateLog($expense);

    $this->assertDatabaseHas('exchange_Rate_logs', [
        'exchange_rate' => $response->exchange_rate,
        'base_currency_id' => $response->base_currency_id,
        'currency_id' => $response->currency_id,
    ]);
});

test('exchange rate log factory aligns company currency semantics', function () {
    $exchangeRateLog = ExchangeRateLog::factory()->create();
    $companyCurrencyId = (int) CompanySetting::getSetting('currency', $exchangeRateLog->company_id);

    expect((int) $exchangeRateLog->currency_id)->toBe($companyCurrencyId)
        ->and(Currency::query()->whereKey($exchangeRateLog->base_currency_id)->exists())->toBeTrue();
});
