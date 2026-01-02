<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'transaction_date' => $this->faker->date(),

            'status' => Transaction::SUCCESS,
            'company_id' => Company::factory(),
            'invoice_id' => Invoice::factory(),

        ];
    }
}
