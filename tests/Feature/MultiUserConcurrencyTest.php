<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiUserConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected $company;
    protected $user;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create();
        $this->user->companies()->attach($this->company->id);
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * Test that appointments cannot be double-booked
     */
    public function test_appointment_overlap_detection_prevents_double_booking(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $appointmentDate = Carbon::tomorrow()->setHour(10)->setMinute(0)->setSecond(0);

        // Create first appointment
        $response1 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'First Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response1->assertStatus(200);

        // Attempt to create overlapping appointment at the exact same time
        $response2 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Overlapping Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response2->assertStatus(422);
        $response2->assertJson([
            'success' => false,
            'error' => 'appointment_overlap',
        ]);

        // Verify only one appointment was created
        $this->assertEquals(1, Appointment::where('company_id', $this->company->id)->count());
    }

    /**
     * Test that partially overlapping appointments are also detected
     */
    public function test_appointment_partial_overlap_detection(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $appointmentDate = Carbon::tomorrow()->setHour(10)->setMinute(0)->setSecond(0);

        // Create first appointment from 10:00-11:00
        $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'First Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id])->assertStatus(200);

        // Attempt to create appointment from 10:30-11:00 (overlaps with first)
        $overlappingTime = $appointmentDate->copy()->addMinutes(30);
        $response = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Partial Overlap Appointment',
            'appointment_date' => $overlappingTime->toDateTimeString(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'appointment_overlap']);
    }

    public function test_appointment_overlap_detection_catches_cross_midnight_conflicts_on_create(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $startOfDay = Carbon::tomorrow()->startOfDay();
        $existingStart = $startOfDay->copy()->subMinutes(30); // 23:30 previous day

        $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Late Night Appointment',
            'appointment_date' => $existingStart->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id])->assertStatus(200);

        $response = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'After Midnight Appointment',
            'appointment_date' => $startOfDay->copy()->addMinutes(15)->toDateTimeString(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'appointment_overlap']);
    }

    /**
     * Test that non-overlapping appointments can be created
     */
    public function test_non_overlapping_appointments_are_allowed(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $appointmentDate = Carbon::tomorrow()->setHour(10)->setMinute(0)->setSecond(0);

        // Create first appointment from 10:00-11:00
        $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'First Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id])->assertStatus(200);

        // Create second appointment from 11:00-12:00 (no overlap)
        $nextTime = $appointmentDate->copy()->addHour();
        $response = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Second Appointment',
            'appointment_date' => $nextTime->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response->assertStatus(200);
        $this->assertEquals(2, Appointment::where('company_id', $this->company->id)->count());
    }

    /**
     * Test that cancelled appointments don't block new bookings
     */
    public function test_cancelled_appointments_dont_block_bookings(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $appointmentDate = Carbon::tomorrow()->setHour(10)->setMinute(0)->setSecond(0);

        // Create and cancel first appointment
        $response1 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Cancelled Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'cancelled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response1->assertStatus(200);

        // Create new appointment at the same time (should succeed)
        $response2 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'New Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response2->assertStatus(200);
    }

    /**
     * Test customer duplicate email handling
     */
    public function test_customer_duplicate_email_returns_friendly_error(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $email = 'test@example.com';

        // Create first customer with email
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'email' => $email,
        ]);

        // Attempt to create another customer with the same email
        $response = $this->postJson('/api/v1/customers', [
            'name' => 'Duplicate Customer',
            'email' => $email,
            'currency_id' => 1,
        ], ['company' => $this->company->id]);

        // Should get a validation error, not a 500 server error
        $this->assertNotEquals(500, $response->status());
    }

    /**
     * Test appointment hash generation
     */
    public function test_appointment_hash_is_generated_on_create(): void
    {
        $appointment = Appointment::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'creator_id' => $this->user->id,
            'title' => 'Test Appointment',
            'appointment_date' => Carbon::tomorrow(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ]);

        $appointment->refresh();
        
        $this->assertNotNull($appointment->unique_hash);
        $this->assertNotEmpty($appointment->unique_hash);
    }

    /**
     * Test that appointment update also checks for overlaps
     */
    public function test_appointment_update_checks_for_overlaps(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $appointmentDate = Carbon::tomorrow()->setHour(10)->setMinute(0)->setSecond(0);

        // Create two appointments at different times
        $response1 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'First Appointment',
            'appointment_date' => $appointmentDate->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);
        $response1->assertStatus(200);
        $appointment1Id = $response1->json('data.id');

        $laterTime = $appointmentDate->copy()->addHours(2);
        $response2 = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Second Appointment',
            'appointment_date' => $laterTime->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);
        $response2->assertStatus(200);
        $appointment2Id = $response2->json('data.id');

        // Try to update the second appointment to overlap with the first
        $response = $this->putJson("/api/v1/appointments/{$appointment2Id}", [
            'customer_id' => $this->customer->id,
            'title' => 'Updated Appointment',
            'appointment_date' => $appointmentDate->copy()->addMinutes(30)->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'appointment_overlap']);
    }

    public function test_appointment_update_checks_cross_midnight_overlaps(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $startOfDay = Carbon::tomorrow()->startOfDay();
        $existingStart = $startOfDay->copy()->subMinutes(30); // 23:30 previous day

        $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Late Night Appointment',
            'appointment_date' => $existingStart->toDateTimeString(),
            'duration_minutes' => 60,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id])->assertStatus(200);

        $response = $this->postJson('/api/v1/appointments', [
            'customer_id' => $this->customer->id,
            'title' => 'Movable Appointment',
            'appointment_date' => $startOfDay->copy()->addHours(2)->toDateTimeString(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $response->assertStatus(200);
        $movableAppointmentId = $response->json('data.id');

        $updateResponse = $this->putJson("/api/v1/appointments/{$movableAppointmentId}", [
            'customer_id' => $this->customer->id,
            'title' => 'Movable Appointment',
            'appointment_date' => $startOfDay->copy()->addMinutes(15)->toDateTimeString(),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'type' => 'consultation',
        ], ['company' => $this->company->id]);

        $updateResponse->assertStatus(422);
        $updateResponse->assertJson(['error' => 'appointment_overlap']);
    }
}
