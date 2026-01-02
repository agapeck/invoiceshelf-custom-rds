<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Appointment::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_id' => Company::factory(),
            'customer_id' => Customer::factory(),
            'creator_id' => User::factory(),
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'appointment_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',

        ];
    }
}
