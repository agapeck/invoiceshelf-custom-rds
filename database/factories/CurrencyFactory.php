<?php

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->currencyCode(),
            'code' => $this->faker->unique()->currencyCode(),
            'symbol' => $this->faker->randomElement(['$', '€', '£', '¥', '₹']),
            'precision' => 2,
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'swap_currency_symbol' => false,
        ];
    }
}
