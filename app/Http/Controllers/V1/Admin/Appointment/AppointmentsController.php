<?php

namespace App\Http\Controllers\V1\Admin\Appointment;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AppointmentsController extends Controller
{
    private const OVERLAP_LOOKBACK_MINUTES = 1440;

    /**
     * Display a listing of appointments.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Appointment::class);

        $limit = $request->has('limit') ? $request->limit : 10;

        $appointments = Appointment::with(['customer', 'creator'])
            ->whereCompany()
            ->applyFilters($request->all())
            ->latest('appointment_date')
            ->paginateData($limit);

        return AppointmentResource::collection($appointments);
    }

    /**
     * Store a newly created appointment.
     * Uses database transaction and locking to prevent double-booking.
     */
    public function store(AppointmentRequest $request)
    {
        $this->authorize('create', Appointment::class);

        $validated = $request->validated();
        $appointmentDate = Carbon::parse($validated['appointment_date']);
        $durationMinutes = $validated['duration_minutes'];
        $companyId = $validated['company_id'];
        $lockKey = sprintf('appointments:%d:%s', $companyId, $appointmentDate->toDateString());

        return Cache::lock($lockKey, 10)->block(5, function () use ($validated, $appointmentDate, $durationMinutes, $companyId) {
            return DB::transaction(function () use ($validated, $appointmentDate, $durationMinutes, $companyId) {
                // Calculate the proposed appointment's time window
                $proposedStart = $appointmentDate;
                $proposedEnd = $appointmentDate->copy()->addMinutes($durationMinutes);

                // Include neighboring-day candidates so overlaps crossing midnight are detected.
                $existingAppointments = $this->getOverlapCandidates($companyId, $proposedStart, $proposedEnd);

                // Check for overlapping appointments
                foreach ($existingAppointments as $existing) {
                    $existingStart = $existing->appointment_date;
                    $existingEnd = $existingStart->copy()->addMinutes($existing->duration_minutes);

                    // Check if the proposed appointment overlaps with an existing one
                    if ($proposedStart->lt($existingEnd) && $proposedEnd->gt($existingStart)) {
                        return response()->json([
                            'success' => false,
                            'error' => 'appointment_overlap',
                            'message' => 'This time slot is no longer available. Another appointment was booked for this time. Please select a different time.',
                            'conflicting_appointment' => [
                                'start' => $existingStart->format('h:i A'),
                                'end' => $existingEnd->format('h:i A'),
                            ],
                        ], 422);
                    }
                }

                // No overlap found, safe to create
                $appointment = Appointment::create($validated);

                return new AppointmentResource($appointment->load(['customer', 'creator']));
            });
        });
    }

    /**
     * Display the specified appointment.
     */
    public function show(Appointment $appointment)
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment->load(['customer', 'creator']));
    }

    /**
     * Update the specified appointment.
     * Uses database transaction and locking to prevent double-booking.
     */
    public function update(AppointmentRequest $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $validated = $request->validated();
        $appointmentDate = Carbon::parse($validated['appointment_date']);
        $durationMinutes = $validated['duration_minutes'];
        $companyId = $validated['company_id'];
        $appointmentId = $appointment->id;
        $lockKey = sprintf('appointments:%d:%s', $companyId, $appointmentDate->toDateString());

        return Cache::lock($lockKey, 10)->block(5, function () use ($validated, $appointmentDate, $durationMinutes, $companyId, $appointmentId, $appointment) {
            return DB::transaction(function () use ($validated, $appointmentDate, $durationMinutes, $companyId, $appointmentId, $appointment) {
                // Calculate the proposed appointment's time window
                $proposedStart = $appointmentDate;
                $proposedEnd = $appointmentDate->copy()->addMinutes($durationMinutes);

                // Include neighboring-day candidates so overlaps crossing midnight are detected.
                $existingAppointments = $this->getOverlapCandidates($companyId, $proposedStart, $proposedEnd, $appointmentId);

                // Check for overlapping appointments
                foreach ($existingAppointments as $existing) {
                    $existingStart = $existing->appointment_date;
                    $existingEnd = $existingStart->copy()->addMinutes($existing->duration_minutes);

                    // Check if the proposed appointment overlaps with an existing one
                    if ($proposedStart->lt($existingEnd) && $proposedEnd->gt($existingStart)) {
                        return response()->json([
                            'success' => false,
                            'error' => 'appointment_overlap',
                            'message' => 'This time slot is no longer available. Another appointment exists at this time. Please select a different time.',
                            'conflicting_appointment' => [
                                'start' => $existingStart->format('h:i A'),
                                'end' => $existingEnd->format('h:i A'),
                            ],
                        ], 422);
                    }
                }

                // No overlap found, safe to update
                $appointment->update($validated);

                return new AppointmentResource($appointment->load(['customer', 'creator']));
            });
        });
    }

    /**
     * Remove the specified appointment.
     */
    public function destroy(Appointment $appointment)
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get available time slots for a given date.
     */
    public function getAvailableSlots(Request $request)
    {
        $companyId = $request->header('company') ?? $request->company_id;

        $request->merge(['company_id' => $companyId]);

        $request->validate([
            'date' => 'required|date',
            'company_id' => 'required|exists:companies,id',
            'exclude_appointment_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('appointments', 'id')
                    ->where('company_id', $companyId),
            ],
        ]);

        $slots = Appointment::getAvailableTimeSlots(
            $request->date,
            $companyId,
            $request->exclude_appointment_id
        );

        return response()->json([
            'slots' => $slots,
        ]);
    }

    /**
     * Update appointment status.
     */
    public function updateStatus(Request $request, Appointment $appointment)
    {
        $this->authorize('update', $appointment);

        $request->validate([
            'status' => 'required|in:scheduled,confirmed,completed,cancelled,no_show',
            'reason' => 'nullable|string',
        ]);

        switch ($request->status) {
            case 'completed':
                $appointment->markAsCompleted();
                break;
            case 'confirmed':
                $appointment->markAsConfirmed();
                break;
            case 'cancelled':
                $appointment->cancel($request->reason);
                break;
            default:
                $appointment->update(['status' => $request->status]);
        }

        return new AppointmentResource($appointment->fresh(['customer', 'creator']));
    }

    /**
     * Get dashboard appointment statistics.
     */
    public function getDashboardStats(Request $request)
    {
        $this->authorize('viewAny', Appointment::class);

        $companyId = $request->header('company');

        $stats = [
            'today' => Appointment::whereCompany($companyId)
                ->today()
                ->count(),
            'this_week' => Appointment::whereCompany($companyId)
                ->thisWeek()
                ->count(),
            'upcoming' => Appointment::whereCompany($companyId)
                ->upcoming()
                ->count(),
            'completed_this_month' => Appointment::whereCompany($companyId)
                ->where('status', 'completed')
                ->whereMonth('appointment_date', now()->month)
                ->whereYear('appointment_date', now()->year)
                ->count(),
        ];

        return response()->json($stats);
    }

    private function getOverlapCandidates(int $companyId, Carbon $proposedStart, Carbon $proposedEnd, ?int $excludeAppointmentId = null)
    {
        return Appointment::where('company_id', $companyId)
            ->where('appointment_date', '>=', $proposedStart->copy()->subMinutes(self::OVERLAP_LOOKBACK_MINUTES))
            ->where('appointment_date', '<', $proposedEnd)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludeAppointmentId, function ($query) use ($excludeAppointmentId) {
                $query->where('id', '!=', $excludeAppointmentId);
            })
            ->lockForUpdate()
            ->get();
    }
}
