<template>
  <BasePage v-if="!isFetchingViewData">
    <BasePageHeader :title="$t('appointments.view_appointment')">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem
          :title="$t('appointments.title')"
          to="/admin/appointments"
        />
        <BaseBreadcrumbItem
          :title="$t('appointments.view_appointment')"
          to="#"
          active
        />
      </BaseBreadcrumb>

      <template #actions>
        <div class="flex items-center justify-end space-x-2">
          <BaseButton
            v-if="userStore.hasAbilities(abilities.EDIT_APPOINTMENT)"
            :to="`/admin/appointments/${$route.params.id}/edit`"
            variant="primary-outline"
          >
            <template #left="slotProps">
              <BaseIcon name="PencilIcon" :class="slotProps.class" />
            </template>
            {{ $t('general.edit') }}
          </BaseButton>

          <BaseDropdown>
            <template #activator>
              <BaseButton variant="primary">
                {{ $t('appointments.actions') }}
                <template #right="slotProps">
                  <BaseIcon name="ChevronDownIcon" :class="slotProps.class" />
                </template>
              </BaseButton>
            </template>

            <BaseDropdownItem
              v-if="
                appointment.status === 'scheduled' &&
                userStore.hasAbilities(abilities.EDIT_APPOINTMENT)
              "
              @click="updateStatus('confirmed')"
            >
              <BaseIcon
                name="CheckCircleIcon"
                class="w-5 h-5 mr-3 text-gray-400"
              />
              {{ $t('appointments.mark_as_confirmed') }}
            </BaseDropdownItem>

            <BaseDropdownItem
              v-if="
                (appointment.status === 'scheduled' ||
                  appointment.status === 'confirmed') &&
                userStore.hasAbilities(abilities.EDIT_APPOINTMENT)
              "
              @click="updateStatus('completed')"
            >
              <BaseIcon
                name="CheckIcon"
                class="w-5 h-5 mr-3 text-gray-400"
              />
              {{ $t('appointments.mark_as_completed') }}
            </BaseDropdownItem>

            <BaseDropdownItem
              v-if="
                (appointment.status === 'scheduled' ||
                  appointment.status === 'confirmed') &&
                userStore.hasAbilities(abilities.EDIT_APPOINTMENT)
              "
              @click="cancelAppointment"
            >
              <BaseIcon name="XCircleIcon" class="w-5 h-5 mr-3 text-gray-400" />
              {{ $t('appointments.cancel') }}
            </BaseDropdownItem>

            <BaseDropdownItem
              v-if="userStore.hasAbilities(abilities.DELETE_APPOINTMENT)"
              @click="deleteAppointment"
            >
              <BaseIcon name="TrashIcon" class="w-5 h-5 mr-3 text-gray-400" />
              {{ $t('general.delete') }}
            </BaseDropdownItem>
          </BaseDropdown>
        </div>
      </template>
    </BasePageHeader>

    <div class="grid grid-cols-1 gap-6 mt-6 lg:grid-cols-3">
      <!-- Main Content (2/3 width) -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Basic Information -->
        <BaseCard>
          <div class="flex items-center justify-between mb-4">
            <h6 class="text-lg font-semibold">
              {{ $t('appointments.basic_info') }}
            </h6>
            <BaseBadge
              :bg-color="getStatusColor(appointment.status)"
              :color="getStatusTextColor(appointment.status)"
              class="px-3 py-1"
            >
              {{ $t(`appointments.status_${appointment.status}`) }}
            </BaseBadge>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.title') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.title }}
              </p>
            </div>

            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.type') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.type ? $t(`appointments.type_${appointment.type}`) : '-' }}
              </p>
            </div>

            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.date') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.formatted_appointment_date }}
              </p>
            </div>

            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.time') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.formatted_appointment_time }}
              </p>
            </div>

            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.duration') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.duration_minutes }} {{ $t('appointments.minutes') }}
              </p>
            </div>

            <div>
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.end_time') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ formatTime(appointment.end_time) }}
              </p>
            </div>
          </div>

          <div v-if="appointment.description" class="mt-4">
            <p class="text-sm font-medium text-gray-500">
              {{ $t('appointments.description') }}
            </p>
            <p class="mt-1 text-base text-gray-900 whitespace-pre-wrap">
              {{ appointment.description }}
            </p>
          </div>
        </BaseCard>

        <!-- Patient Details -->
        <BaseCard>
          <h6 class="mb-4 text-lg font-semibold">
            {{ $t('appointments.patient_details') }}
          </h6>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div v-if="appointment.patient_name">
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.patient_name') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.patient_name }}
              </p>
            </div>

            <div v-if="appointment.patient_phone">
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.patient_phone') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.patient_phone }}
              </p>
            </div>

            <div v-if="appointment.patient_email">
              <p class="text-sm font-medium text-gray-500">
                {{ $t('appointments.patient_email') }}
              </p>
              <p class="mt-1 text-base text-gray-900">
                {{ appointment.patient_email }}
              </p>
            </div>
          </div>

          <div v-if="appointment.chief_complaint" class="mt-4">
            <p class="text-sm font-medium text-gray-500">
              {{ $t('appointments.chief_complaint') }}
            </p>
            <p class="mt-1 text-base text-gray-900 whitespace-pre-wrap">
              {{ appointment.chief_complaint }}
            </p>
          </div>
        </BaseCard>

        <!-- Notes & Instructions -->
        <BaseCard v-if="appointment.notes || appointment.preparation_instructions">
          <h6 class="mb-4 text-lg font-semibold">
            {{ $t('appointments.notes_and_instructions') }}
          </h6>

          <div v-if="appointment.notes" class="mb-4">
            <p class="text-sm font-medium text-gray-500">
              {{ $t('appointments.notes') }}
            </p>
            <p class="mt-1 text-base text-gray-900 whitespace-pre-wrap">
              {{ appointment.notes }}
            </p>
          </div>

          <div v-if="appointment.preparation_instructions">
            <p class="text-sm font-medium text-gray-500">
              {{ $t('appointments.preparation_instructions') }}
            </p>
            <p class="mt-1 text-base text-gray-900 whitespace-pre-wrap">
              {{ appointment.preparation_instructions }}
            </p>
          </div>
        </BaseCard>
      </div>

      <!-- Sidebar (1/3 width) -->
      <div class="space-y-6">
        <!-- Customer Information -->
        <BaseCard>
          <h6 class="mb-4 text-lg font-semibold">
            {{ $t('customers.customer') }}
          </h6>

          <div v-if="appointment.customer">
            <router-link
              :to="`/admin/customers/${appointment.customer.id}/view`"
              class="flex items-center p-3 transition-colors rounded-lg hover:bg-gray-50"
            >
              <div class="flex-shrink-0 w-10 h-10">
                <div
                  class="flex items-center justify-center w-10 h-10 rounded-full bg-primary-100"
                >
                  <span class="text-lg font-semibold text-primary-600">
                    {{ appointment.customer.name.charAt(0).toUpperCase() }}
                  </span>
                </div>
              </div>
              <div class="ml-3">
                <p class="text-sm font-medium text-gray-900">
                  {{ appointment.customer.name }}
                </p>
                <p v-if="appointment.customer.email" class="text-sm text-gray-500">
                  {{ appointment.customer.email }}
                </p>
              </div>
            </router-link>
          </div>
        </BaseCard>

        <!-- Reminder Settings -->
        <BaseCard>
          <h6 class="mb-4 text-lg font-semibold">
            {{ $t('appointments.reminder_settings') }}
          </h6>

          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <p class="text-sm text-gray-600">
                {{ $t('appointments.send_reminder') }}
              </p>
              <BaseBadge
                :bg-color="appointment.send_reminder ? 'bg-green-100' : 'bg-gray-100'"
                :color="appointment.send_reminder ? 'text-green-800' : 'text-gray-800'"
              >
                {{ appointment.send_reminder ? $t('general.yes') : $t('general.no') }}
              </BaseBadge>
            </div>

            <div v-if="appointment.send_reminder">
              <p class="text-sm text-gray-600">
                {{ $t('appointments.reminder_time') }}
              </p>
              <p class="mt-1 text-sm font-medium text-gray-900">
                {{ appointment.reminder_hours_before }} {{ $t('appointments.hours_before') }}
              </p>
            </div>

            <div v-if="appointment.reminder_sent_at">
              <p class="text-sm text-gray-600">
                {{ $t('appointments.reminder_sent') }}
              </p>
              <p class="mt-1 text-sm font-medium text-gray-900">
                {{ formatDateTime(appointment.reminder_sent_at) }}
              </p>
            </div>
          </div>
        </BaseCard>

        <!-- Metadata -->
        <BaseCard>
          <h6 class="mb-4 text-lg font-semibold">
            {{ $t('general.metadata') }}
          </h6>

          <div class="space-y-3">
            <div>
              <p class="text-sm text-gray-600">{{ $t('general.created_at') }}</p>
              <p class="mt-1 text-sm font-medium text-gray-900">
                {{ formatDateTime(appointment.created_at) }}
              </p>
            </div>

            <div>
              <p class="text-sm text-gray-600">{{ $t('general.updated_at') }}</p>
              <p class="mt-1 text-sm font-medium text-gray-900">
                {{ formatDateTime(appointment.updated_at) }}
              </p>
            </div>

            <div v-if="appointment.creator">
              <p class="text-sm text-gray-600">{{ $t('general.created_by') }}</p>
              <p class="mt-1 text-sm font-medium text-gray-900">
                {{ appointment.creator.name }}
              </p>
            </div>
          </div>
        </BaseCard>
      </div>
    </div>
  </BasePage>

  <!-- Loading State -->
  <BasePage v-else>
    <div class="flex items-center justify-center h-64">
      <BaseIcon name="RefreshIcon" class="w-8 h-8 text-gray-400 animate-spin" />
    </div>
  </BasePage>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAppointmentStore } from '@/scripts/admin/stores/appointment'
import { useUserStore } from '@/scripts/admin/stores/user'
import { useDialogStore } from '@/scripts/stores/dialog'
import abilities from '@/scripts/admin/stub/abilities'
import moment from 'moment'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const appointmentStore = useAppointmentStore()
const userStore = useUserStore()
const dialogStore = useDialogStore()

const isFetchingViewData = ref(true)

const appointment = computed(() => appointmentStore.selectedViewAppointment)

function getStatusColor(status) {
  const colors = {
    scheduled: 'bg-blue-100',
    confirmed: 'bg-green-100',
    completed: 'bg-gray-100',
    cancelled: 'bg-red-100',
    no_show: 'bg-yellow-100',
  }
  return colors[status] || 'bg-gray-100'
}

function getStatusTextColor(status) {
  const colors = {
    scheduled: 'text-blue-800',
    confirmed: 'text-green-800',
    completed: 'text-gray-800',
    cancelled: 'text-red-800',
    no_show: 'text-yellow-800',
  }
  return colors[status] || 'text-gray-800'
}

function formatTime(timeString) {
  if (!timeString) return ''
  return moment(timeString).format('h:mm A')
}

function formatDateTime(dateTimeString) {
  if (!dateTimeString) return ''
  return moment(dateTimeString).format('MMM D, YYYY h:mm A')
}

async function updateStatus(status) {
  await appointmentStore.updateAppointmentStatus(route.params.id, status)
  await loadAppointment()
}

function cancelAppointment() {
  dialogStore
    .openDialog({
      title: t('appointments.cancel_appointment'),
      message: t('appointments.confirm_cancel'),
      yesLabel: t('general.yes'),
      noLabel: t('general.no'),
    })
    .then(async (response) => {
      if (response) {
        await appointmentStore.updateAppointmentStatus(route.params.id, 'cancelled')
        await loadAppointment()
      }
    })
}

function deleteAppointment() {
  dialogStore
    .openDialog({
      title: t('general.are_you_sure'),
      message: t('appointments.confirm_delete'),
      yesLabel: t('general.ok'),
      noLabel: t('general.cancel'),
    })
    .then(async (response) => {
      if (response) {
        await appointmentStore.deleteAppointment(route.params.id)
        router.push('/admin/appointments')
      }
    })
}

async function loadAppointment() {
  isFetchingViewData.value = true
  try {
    await appointmentStore.fetchViewAppointment(route.params.id)
  } catch (error) {
    console.error('Error loading appointment:', error)
  } finally {
    isFetchingViewData.value = false
  }
}

onMounted(() => {
  loadAppointment()
})
</script>
