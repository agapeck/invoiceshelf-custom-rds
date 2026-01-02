<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

use Vinkla\Hashids\Facades\Hashids;
use App\Traits\GeneratesHashTrait;

class Appointment extends Model
{
    use HasFactory;
    use GeneratesHashTrait;

    protected $guarded = ['id'];

    // Booted is now handled by GeneratesHashTrait::bootGeneratesHashTrait
    // protected static function booted()
    // {
    //     static::created(function (self $appointment) {
    //         if (! $appointment->unique_hash) {
    //             try {
    //                 $appointment->unique_hash = Hashids::connection(self::class)->encode($appointment->id);
    //                 $appointment->saveQuietly();
    //             } catch (\Throwable $e) {
    //                 // Log the error but don't fail the appointment creation
    //                 // The unique_hash can be regenerated later if needed
    //                 Log::error('Failed to generate unique_hash for appointment', [
    //                     'appointment_id' => $appointment->id,
    //                     'error' => $e->getMessage(),
    //                 ]);
    //             }
    //         }
    //     });
    // }

    protected $appends = [
        'formatted_appointment_date',
        'formatted_appointment_time',
        'is_past_due',
        'status_badge_color',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'datetime',
            'send_reminder' => 'boolean',
            'reminder_sent_at' => 'datetime',
        ];
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    // Accessors
    public function getFormattedAppointmentDateAttribute()
    {
        return $this->appointment_date ? $this->appointment_date->format('M d, Y') : null;
    }

    public function getFormattedAppointmentTimeAttribute()
    {
        return $this->appointment_date ? $this->appointment_date->format('h:i A') : null;
    }

    public function getIsPastDueAttribute()
    {
        return $this->appointment_date && $this->appointment_date->isPast() && $this->status !== 'completed';
    }

    public function getStatusBadgeColorAttribute()
    {
        return match($this->status) {
            'scheduled' => 'blue',
            'confirmed' => 'green',
            'completed' => 'gray',
            'cancelled' => 'red',
            'no_show' => 'yellow',
            default => 'gray',
        };
    }

    // Scopes
    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>', now())
                    ->whereIn('status', ['scheduled', 'confirmed']);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('appointment_date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('appointment_date', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeNeedingReminder($query)
    {
        return $query->where('send_reminder', true)
                    ->whereNull('reminder_sent_at')
                    ->where('appointment_date', '>', now())
                    ->whereRaw('appointment_date <= DATE_ADD(NOW(), INTERVAL reminder_hours_before HOUR)');
    }

    public function scopeWhereCompany($query, $companyId = null)
    {
        $companyId = $companyId ?: request()->header('company');
        return $query->where('company_id', $companyId);
    }

    // Methods
    public function markAsCompleted()
    {
        $this->update(['status' => 'completed']);
    }

    public function markAsConfirmed()
    {
        $this->update(['status' => 'confirmed']);
    }

    public function cancel($reason = null)
    {
        $this->update([
            'status' => 'cancelled',
            'notes' => $this->notes . ($reason ? "\n\nCancellation reason: " . $reason : '')
        ]);
    }

    public function markReminderSent()
    {
        $this->update(['reminder_sent_at' => now()]);
    }

    /**
     * Regenerate unique_hash if missing.
     * This can be called for maintenance purposes.
     */
    public function ensureUniqueHash(): bool
    {
        if ($this->unique_hash) {
            return true;
        }

        try {
            $this->unique_hash = Hashids::connection(self::class)->encode($this->id);
            return $this->saveQuietly();
        } catch (\Throwable $e) {
            Log::error('Failed to regenerate unique_hash for appointment', [
                'appointment_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Regenerate all missing unique_hash values.
     * Useful for maintenance after issues.
     */
    public static function regenerateMissingHashes(): array
    {
        $results = ['success' => 0, 'failed' => 0];
        
        self::whereNull('unique_hash')->orWhere('unique_hash', '')->chunk(100, function ($appointments) use (&$results) {
            foreach ($appointments as $appointment) {
                if ($appointment->ensureUniqueHash()) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            }
        });
        
        return $results;
    }

    public function canBeModified()
    {
        return !in_array($this->status, ['completed', 'cancelled']) && 
               $this->appointment_date && 
               $this->appointment_date->isFuture();
    }

    public function getEndTime()
    {
        return $this->appointment_date->copy()->addMinutes($this->duration_minutes);
    }

    public static function getAvailableTimeSlots($date, $companyId, $excludeAppointmentId = null)
    {
        $startHour = 9; // 9 AM
        $endHour = 17;  // 5 PM
        $slotDuration = 30; // 30 minutes
        
        $slots = [];
        $currentTime = Carbon::parse($date)->setHour($startHour)->setMinute(0);
        $endTime = Carbon::parse($date)->setHour($endHour)->setMinute(0);
        
        while ($currentTime->lt($endTime)) {
            $slots[] = $currentTime->format('H:i');
            $currentTime->addMinutes($slotDuration);
        }
        
        // Get all appointments for the date (excluding cancelled)
        $appointments = self::where('company_id', $companyId)
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->when($excludeAppointmentId, function($query) use ($excludeAppointmentId) {
                return $query->where('id', '!=', $excludeAppointmentId);
            })
            ->get();

        // Calculate blocked slots based on appointment duration
        $blockedSlots = [];
        foreach ($appointments as $appointment) {
            $appointmentStart = $appointment->appointment_date;
            $appointmentEnd = $appointmentStart->copy()->addMinutes($appointment->duration_minutes);
            
            // Check each slot to see if it overlaps with this appointment
            foreach ($slots as $slot) {
                $slotStart = Carbon::parse($date . ' ' . $slot);
                $slotEnd = $slotStart->copy()->addMinutes($slotDuration);
                
                // If slot overlaps with appointment, mark it as blocked
                if ($slotStart->lt($appointmentEnd) && $slotEnd->gt($appointmentStart)) {
                    $blockedSlots[] = $slot;
                }
            }
        }

        return array_values(array_diff($slots, array_unique($blockedSlots)));
    }

    public function scopeApplyFilters($query, array $filters)
    {
        $filters = collect($filters)->filter()->all();

        return $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', '%'.$search.'%')
                    ->orWhere('patient_name', 'LIKE', '%'.$search.'%')
                    ->orWhere('patient_phone', 'LIKE', '%'.$search.'%')
                    ->orWhere('patient_email', 'LIKE', '%'.$search.'%')
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'LIKE', '%'.$search.'%');
                    });
            });
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['type'] ?? null, function ($query, $type) {
            $query->where('type', $type);
        })->when($filters['customer_id'] ?? null, function ($query, $customerId) {
            $query->where('customer_id', $customerId);
        })->when(($filters['from_date'] ?? null) && ($filters['to_date'] ?? null), function ($query) use ($filters) {
            $start = Carbon::parse($filters['from_date']);
            $end = Carbon::parse($filters['to_date']);
            $query->whereBetween('appointment_date', [$start, $end]);
        })->when($filters['orderByField'] ?? null, function ($query, $orderByField) use ($filters) {
            $orderBy = $filters['orderBy'] ?? 'desc';
            $query->orderBy($orderByField, $orderBy);
        }, function ($query) {
            $query->orderBy('appointment_date', 'desc');
        });
    }

    public function scopePaginateData($query, $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }
}
