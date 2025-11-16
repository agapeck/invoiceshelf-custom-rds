<template>
  <BasePage>
    <form @submit.prevent="submitAppointmentData">
      <BasePageHeader :title="pageTitle">
        <BaseBreadcrumb>
          <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
          <BaseBreadcrumbItem :title="$t('appointments.title')" to="/admin/appointments" />
          <BaseBreadcrumbItem :title="pageTitle" to="#" active />
        </BaseBreadcrumb>

        <template #actions>
          <div class="flex items-center justify-end space-x-2">
            <BaseButton
              type="button"
              :to="`/admin/appointments`"
              variant="primary-outline"
            >
              {{ $t('general.cancel') }}
            </BaseButton>

            <BaseButton
              type="submit"
              :loading="isSaving"
              :disabled="isSaving"
              variant="primary"
            >
              <template #left="slotProps">
                <BaseIcon name="SaveIcon" :class="slotProps.class" />
              </template>
              {{ isEdit ? $t('appointments.update_appointment') : $t('appointments.save_appointment') }}
            </BaseButton>
          </div>
        </template>
      </BasePageHeader>

      <!-- Basic Information Section -->
      <BaseCard class="mt-6">
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold lg:col-span-1">
            {{ $t('appointments.basic_info') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <!-- Customer Selection -->
            <BaseInputGroup
              :label="$t('appointments.customer')"
              required
              :error="v$.formData.customer_id.$error && v$.formData.customer_id.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseCustomerSelectInput
                v-model="formData.customer_id"
                :invalid="v$.formData.customer_id.$error"
                :content-loading="isFetchingInitialData"
                @update:modelValue="onCustomerChange"
              />
            </BaseInputGroup>

            <!-- Title -->
            <BaseInputGroup
              :label="$t('appointments.title')"
              required
              :error="v$.formData.title.$error && v$.formData.title.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="formData.title"
                :content-loading="isFetchingInitialData"
                type="text"
                name="title"
                :invalid="v$.formData.title.$error"
                @input="v$.formData.title.$touch()"
              />
            </BaseInputGroup>

            <!-- Type -->
            <BaseInputGroup
              :label="$t('appointments.type')"
              required
              :error="v$.formData.type.$error && v$.formData.type.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseSelect
                v-model="formData.type"
                :options="typeOptions"
                :invalid="v$.formData.type.$error"
                :content-loading="isFetchingInitialData"
                :searchable="true"
                :show-labels="false"
                label="text"
                track-by="value"
                @input="v$.formData.type.$touch()"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>

      <!-- Date & Time Section -->
      <BaseCard class="mt-6">
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold lg:col-span-1">
            {{ $t('appointments.schedule') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <!-- Appointment Date -->
            <BaseInputGroup
              :label="$t('appointments.date')"
              required
              :error="v$.formData.appointment_date.$error && v$.formData.appointment_date.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseDatePicker
                v-model="appointmentDate"
                :calendar-button="true"
                calendar-button-icon="calendar"
                :invalid="v$.formData.appointment_date.$error"
                :content-loading="isFetchingInitialData"
                @update:modelValue="onDateChange"
              />
            </BaseInputGroup>

            <!-- Time Slot -->
            <BaseInputGroup
              :label="$t('appointments.time')"
              required
              :error="timeSlotError"
              :content-loading="isFetchingInitialData || loadingSlots"
            >
              <BaseSelect
                v-model="appointmentTime"
                :options="availableTimeSlots"
                :invalid="!!timeSlotError"
                :content-loading="isFetchingInitialData || loadingSlots"
                :searchable="true"
                :show-labels="false"
                :placeholder="appointmentDate ? $t('appointments.select_time') : $t('appointments.select_date_first')"
                :disabled="!appointmentDate || loadingSlots"
                label="text"
                track-by="value"
                @update:modelValue="onTimeChange"
              />
              <p v-if="loadingSlots" class="mt-1 text-sm text-gray-500">
                {{ $t('appointments.loading_slots') }}
              </p>
            </BaseInputGroup>

            <!-- Duration -->
            <BaseInputGroup
              :label="$t('appointments.duration')"
              required
              :error="v$.formData.duration_minutes.$error && v$.formData.duration_minutes.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseSelect
                v-model="formData.duration_minutes"
                :options="durationOptions"
                :invalid="v$.formData.duration_minutes.$error"
                :content-loading="isFetchingInitialData"
                :searchable="true"
                :show-labels="false"
                label="text"
                track-by="value"
              />
            </BaseInputGroup>

            <!-- Status -->
            <BaseInputGroup
              :label="$t('appointments.status')"
              required
              :content-loading="isFetchingInitialData"
            >
              <BaseSelect
                v-model="formData.status"
                :options="statusOptions"
                :content-loading="isFetchingInitialData"
                :searchable="true"
                :show-labels="false"
                label="text"
                track-by="value"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>

      <!-- Patient Details Section -->
      <BaseCard class="mt-6">
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold lg:col-span-1">
            {{ $t('appointments.patient_details') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <!-- Patient Name -->
            <BaseInputGroup
              :label="$t('appointments.patient_name')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="formData.patient_name"
                :content-loading="isFetchingInitialData"
                type="text"
                name="patient_name"
              />
            </BaseInputGroup>

            <!-- Patient Phone -->
            <BaseInputGroup
              :label="$t('appointments.patient_phone')"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="formData.patient_phone"
                :content-loading="isFetchingInitialData"
                type="text"
                name="patient_phone"
              />
            </BaseInputGroup>

            <!-- Patient Email -->
            <BaseInputGroup
              :label="$t('appointments.patient_email')"
              :error="v$.formData.patient_email.$error && v$.formData.patient_email.$errors[0].$message"
              :content-loading="isFetchingInitialData"
            >
              <BaseInput
                v-model="formData.patient_email"
                :content-loading="isFetchingInitialData"
                :invalid="v$.formData.patient_email.$error"
                type="email"
                name="patient_email"
                @input="v$.formData.patient_email.$touch()"
              />
            </BaseInputGroup>

            <!-- Chief Complaint -->
            <BaseInputGroup
              :label="$t('appointments.chief_complaint')"
              :content-loading="isFetchingInitialData"
              class="col-span-2"
            >
              <BaseTextarea
                v-model="formData.chief_complaint"
                :content-loading="isFetchingInitialData"
                :rows="3"
                name="chief_complaint"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>

      <!-- Additional Information Section -->
      <BaseCard class="mt-6">
        <div class="grid grid-cols-5 gap-4 mb-8">
          <h6 class="col-span-5 text-lg font-semibold lg:col-span-1">
            {{ $t('appointments.additional_info') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <!-- Description -->
            <BaseInputGroup
              :label="$t('appointments.description')"
              :content-loading="isFetchingInitialData"
              class="col-span-2"
            >
              <BaseTextarea
                v-model="formData.description"
                :content-loading="isFetchingInitialData"
                :rows="3"
                name="description"
              />
            </BaseInputGroup>

            <!-- Notes -->
            <BaseInputGroup
              :label="$t('appointments.notes')"
              :content-loading="isFetchingInitialData"
              class="col-span-2"
            >
              <BaseTextarea
                v-model="formData.notes"
                :content-loading="isFetchingInitialData"
                :rows="3"
                name="notes"
              />
            </BaseInputGroup>

            <!-- Preparation Instructions -->
            <BaseInputGroup
              :label="$t('appointments.preparation_instructions')"
              :content-loading="isFetchingInitialData"
              class="col-span-2"
            >
              <BaseTextarea
                v-model="formData.preparation_instructions"
                :content-loading="isFetchingInitialData"
                :rows="3"
                name="preparation_instructions"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>

      <!-- Reminder Settings Section -->
      <BaseCard class="mt-6">
        <div class="grid grid-cols-5 gap-4">
          <h6 class="col-span-5 text-lg font-semibold lg:col-span-1">
            {{ $t('appointments.reminder_settings') }}
          </h6>

          <BaseInputGrid class="col-span-5 lg:col-span-4">
            <!-- Send Reminder -->
            <BaseInputGroup
              :label="$t('appointments.send_reminder')"
              :content-loading="isFetchingInitialData"
            >
              <BaseSwitch
                v-model="formData.send_reminder"
                :content-loading="isFetchingInitialData"
              />
            </BaseInputGroup>

            <!-- Reminder Hours Before -->
            <BaseInputGroup
              v-if="formData.send_reminder"
              :label="$t('appointments.reminder_hours_before')"
              :content-loading="isFetchingInitialData"
            >
              <BaseSelect
                v-model="formData.reminder_hours_before"
                :options="reminderOptions"
                :content-loading="isFetchingInitialData"
                :searchable="true"
                :show-labels="false"
                label="text"
                track-by="value"
              />
            </BaseInputGroup>
          </BaseInputGrid>
        </div>
      </BaseCard>
    </form>
  </BasePage>
</template>

<script setup>
import { computed, ref, reactive, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import useVuelidate from '@vuelidate/core'
import { required, email } from '@vuelidate/validators'
import { useAppointmentStore } from '@/scripts/admin/stores/appointment'
import { useCustomerStore } from '@/scripts/admin/stores/customer'
import moment from 'moment'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const appointmentStore = useAppointmentStore()
const customerStore = useCustomerStore()

// State
const isSaving = ref(false)
const isFetchingInitialData = ref(false)
const loadingSlots = ref(false)
const appointmentDate = ref('')
const appointmentTime = ref('')
const availableTimeSlots = ref([])
const timeSlotError = ref('')

// Form data (separate from store for better validation control)
const formData = reactive({
  customer_id: null,
  title: '',
  description: '',
  appointment_date: null,
  duration_minutes: 30,
  status: 'scheduled',
  type: 'consultation',
  patient_name: '',
  patient_phone: '',
  patient_email: '',
  chief_complaint: '',
  notes: '',
  preparation_instructions: '',
  send_reminder: true,
  reminder_hours_before: 24,
})

// Computed
const isEdit = computed(() => route.name === 'appointments.edit')
const pageTitle = computed(() =>
  isEdit.value ? t('appointments.edit_appointment') : t('appointments.new_appointment')
)

const typeOptions = [
  { value: 'consultation', text: t('appointments.type_consultation') },
  { value: 'follow_up', text: t('appointments.type_follow_up') },
  { value: 'cleaning', text: t('appointments.type_cleaning') },
  { value: 'filling', text: t('appointments.type_filling') },
  { value: 'extraction', text: t('appointments.type_extraction') },
  { value: 'root_canal', text: t('appointments.type_root_canal') },
  { value: 'crown_bridge', text: t('appointments.type_crown_bridge') },
  { value: 'denture', text: t('appointments.type_denture') },
  { value: 'whitening', text: t('appointments.type_whitening') },
  { value: 'pediatric', text: t('appointments.type_pediatric') },
  { value: 'ortho_consult', text: t('appointments.type_ortho_consult') },
  { value: 'emergency', text: t('appointments.type_emergency') },
  { value: 'treatment', text: t('appointments.type_treatment') },
  { value: 'other', text: t('appointments.type_other') },
]

const statusOptions = [
  { value: 'scheduled', text: t('appointments.status_scheduled') },
  { value: 'confirmed', text: t('appointments.status_confirmed') },
  { value: 'completed', text: t('appointments.status_completed') },
  { value: 'cancelled', text: t('appointments.status_cancelled') },
  { value: 'no_show', text: t('appointments.status_no_show') },
]

const durationOptions = [
  { value: 15, text: '15 ' + t('appointments.minutes') },
  { value: 30, text: '30 ' + t('appointments.minutes') },
  { value: 45, text: '45 ' + t('appointments.minutes') },
  { value: 60, text: '1 ' + t('appointments.hour') },
  { value: 90, text: '1.5 ' + t('appointments.hours') },
  { value: 120, text: '2 ' + t('appointments.hours') },
]

const reminderOptions = [
  { value: 1, text: '1 ' + t('appointments.hour_before') },
  { value: 2, text: '2 ' + t('appointments.hours_before') },
  { value: 4, text: '4 ' + t('appointments.hours_before') },
  { value: 8, text: '8 ' + t('appointments.hours_before') },
  { value: 12, text: '12 ' + t('appointments.hours_before') },
  { value: 24, text: '24 ' + t('appointments.hours_before') },
  { value: 48, text: '48 ' + t('appointments.hours_before') },
]

// Validation
const rules = computed(() => ({
  formData: {
    customer_id: { required },
    title: { required },
    type: { required },
    appointment_date: { required },
    duration_minutes: { required },
    patient_email: { email },
  },
}))

const v$ = useVuelidate(rules, { formData })

// Methods
async function loadAvailableSlots() {
  if (!appointmentDate.value) return

  loadingSlots.value = true
  timeSlotError.value = ''
  
  try {
    await appointmentStore.fetchAvailableSlots(
      appointmentDate.value,
      isEdit.value ? route.params.id : null
    )

    availableTimeSlots.value = appointmentStore.availableSlots.map((slot) => ({
      value: slot,
      text: formatTime(slot),
    }))

    if (availableTimeSlots.value.length === 0) {
      timeSlotError.value = t('appointments.no_slots_available')
    }
  } catch (error) {
    console.error('Error loading time slots:', error)
    timeSlotError.value = t('appointments.error_loading_slots')
  } finally {
    loadingSlots.value = false
  }
}

function formatTime(time) {
  const [hours, minutes] = time.split(':')
  const hour = parseInt(hours)
  const ampm = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 || 12
  return `${displayHour}:${minutes.padStart(2, '0')} ${ampm}`
}

function onDateChange() {
  appointmentTime.value = ''
  formData.appointment_date = null
  loadAvailableSlots()
}

function onTimeChange() {
  if (appointmentDate.value && appointmentTime.value) {
    formData.appointment_date = `${appointmentDate.value} ${appointmentTime.value}:00`
  }
}

async function onCustomerChange(customerId) {
  if (!customerId) return

  try {
    const response = await customerStore.fetchCustomer(customerId)
    const customer = response.data.data

    // Auto-fill patient details from customer if available
    if (customer.name && !formData.patient_name) {
      formData.patient_name = customer.name
    }
    if (customer.phone && !formData.patient_phone) {
      formData.patient_phone = customer.phone
    }
    if (customer.email && !formData.patient_email) {
      formData.patient_email = customer.email
    }
  } catch (error) {
    console.error('Error fetching customer:', error)
  }
}

async function submitAppointmentData() {
  v$.value.$touch()

  if (v$.value.$invalid) {
    return
  }

  if (!appointmentTime.value) {
    timeSlotError.value = t('appointments.time_required')
    return
  }

  isSaving.value = true

  try {
    const appointmentData = { ...formData }

    if (isEdit.value) {
      await appointmentStore.updateAppointment(appointmentData)
    } else {
      await appointmentStore.addAppointment(appointmentData)
    }

    router.push('/admin/appointments')
  } catch (error) {
    console.error('Error saving appointment:', error)
  } finally {
    isSaving.value = false
  }
}

// Lifecycle
onMounted(async () => {
  if (isEdit.value) {
    isFetchingInitialData.value = true
    
    try {
      await appointmentStore.fetchAppointment(route.params.id)
      
      // Populate form with existing data
      const appointment = appointmentStore.currentAppointment
      Object.keys(formData).forEach((key) => {
        if (appointment[key] !== undefined) {
          formData[key] = appointment[key]
        }
      })

      // Split appointment_date into date and time
      if (appointment.appointment_date) {
        const dateTime = moment(appointment.appointment_date)
        appointmentDate.value = dateTime.format('YYYY-MM-DD')
        appointmentTime.value = dateTime.format('HH:mm')
        
        // Load available slots for the date
        await loadAvailableSlots()
      }
    } catch (error) {
      console.error('Error loading appointment:', error)
    } finally {
      isFetchingInitialData.value = false
    }
  }
})

// Watch for date changes
watch(appointmentDate, (newDate) => {
  if (newDate) {
    loadAvailableSlots()
  }
})
</script>
