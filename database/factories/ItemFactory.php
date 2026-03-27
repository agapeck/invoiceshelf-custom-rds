<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Item;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Item::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $user = User::query()->first();
        $companyId = $user?->companies()->first()?->id ?? Company::query()->value('id');
        $currencyId = Currency::query()->first()?->id;

        return [
            'name' => $this->faker->name(),
            'description' => $this->faker->text(),
            'company_id' => $companyId ?? Company::factory(),
            'price' => $this->faker->randomDigitNotNull(),
            'unit_id' => Unit::factory(),
            'creator_id' => $user?->id ?? User::factory(),
            'currency_id' => $currencyId ?? Currency::factory(),
            'tax_per_item' => $this->faker->randomElement([true, false]),
        ];
    }
}
