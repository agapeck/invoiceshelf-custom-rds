<template>
  <BasePage>
    <!-- Page Header -->
    <BasePageHeader :title="$t('appointments.title')">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem
          :title="$t('appointments.appointment', 2)"
          to="#"
          active
        />
      </BaseBreadcrumb>

      <template #actions>
        <div class="flex items-center justify-end space-x-5">
          <BaseButton
            v-show="appointmentStore.totalAppointments"
            variant="primary-outline"
            @click="toggleFilter"
          >
            {{ $t('general.filter') }}
            <template #right="slotProps">
              <BaseIcon
                v-if="!showFilters"
                name="FunnelIcon"
                :class="slotProps.class"
              />
              <BaseIcon v-else name="XMarkIcon" :class="slotProps.class" />
            </template>
          </BaseButton>

          <BaseButton
            v-if="userStore.hasAbilities(abilities.CREATE_APPOINTMENT)"
            @click="$router.push('appointments/create')"
          >
            <template #left="slotProps">
              <BaseIcon name="PlusIcon" :class="slotProps.class" />
            </template>
            {{ $t('appointments.new_appointment') }}
          </BaseButton>
        </div>
      </template>
    </BasePageHeader>

    <!-- Filters -->
    <BaseFilterWrapper :show="showFilters" class="mt-5" @clear="clearFilter">
      <BaseInputGroup :label="$t('general.search')" class="text-left">
        <BaseInput
          v-model="filters.search"
          type="text"
          name="search"
          autocomplete="off"
        />
      </BaseInputGroup>

      <BaseInputGroup :label="$t('appointments.status')" class="text-left">
        <BaseSelect
          v-model="filters.status"
          :options="statusOptions"
          :searchable="true"
          :show-labels="false"
          :placeholder="$t('general.select_status')"
          label="text"
          track-by="value"
        />
      </BaseInputGroup>

      <BaseInputGroup :label="$t('appointments.type')" class="text-left">
        <BaseSelect
          v-model="filters.type"
          :options="typeOptions"
          :searchable="true"
          :show-labels="false"
          :placeholder="$t('general.select_type')"
          label="text"
          track-by="value"
        />
      </BaseInputGroup>

      <BaseInputGroup :label="$t('general.from')" class="text-left">
        <BaseDatePicker
          v-model="filters.from_date"
          :calendar-button="true"
          calendar-button-icon="calendar"
        />
      </BaseInputGroup>

      <BaseInputGroup :label="$t('general.to')" class="text-left">
        <BaseDatePicker
          v-model="filters.to_date"
          :calendar-button="true"
          calendar-button-icon="calendar"
        />
      </BaseInputGroup>
    </BaseFilterWrapper>

    <!-- Empty State -->
    <BaseEmptyPlaceholder
      v-show="showEmptyScreen"
      :title="$t('appointments.no_appointments')"
      :description="$t('appointments.list_of_appointments')"
    >
      <AstronautIcon class="mt-5 mb-4" />

      <template #actions>
        <BaseButton
          v-if="userStore.hasAbilities(abilities.CREATE_APPOINTMENT)"
          variant="primary-outline"
          @click="$router.push('/admin/appointments/create')"
        >
          <template #left="slotProps">
            <BaseIcon name="PlusIcon" :class="slotProps.class" />
          </template>
          {{ $t('appointments.new_appointment') }}
        </BaseButton>
      </template>
    </BaseEmptyPlaceholder>

    <!-- Table -->
    <div v-show="!showEmptyScreen" class="relative table-container">
      <div
        class="relative flex items-center justify-between h-10 mt-5 list-none border-b-2 border-gray-200 border-solid"
      >
        <p class="text-sm">
          {{ $t('general.showing') }}: <b>{{ appointmentStore.appointments.length }}</b>
          {{ $t('general.of') }} <b>{{ appointmentStore.totalAppointments }}</b>
        </p>
      </div>

      <BaseTable
        ref="table"
        :data="appointmentStore.appointments"
        :columns="appointmentColumns"
        :placeholder-count="5"
        class="mt-3"
      >
        <template #cell-appointment_date="{ row }">
          <div class="flex flex-col">
            <span class="font-medium">{{ row.data.formatted_appointment_date }}</span>
            <span class="text-sm text-gray-500">{{ row.data.formatted_appointment_time }}</span>
          </div>
        </template>

        <template #cell-customer="{ row }">
          <router-link
            :to="`/admin/customers/${row.data.customer_id}/view`"
            class="font-medium text-primary-500"
          >
            {{ row.data.customer?.name }}
          </router-link>
        </template>

        <template #cell-status="{ row }">
          <BaseBadge
            :bg-color="getStatusColor(row.data.status)"
            :color="getStatusTextColor(row.data.status)"
            class="px-3 py-1"
          >
            {{ $t(`appointments.status_${row.data.status}`) }}
          </BaseBadge>
        </template>

        <template #cell-type="{ row }">
          {{ row.data.type ? $t(`appointments.type_${row.data.type}`) : '-' }}
        </template>

        <template #cell-actions="{ row }">
          <AppointmentDropdown
            :row="row.data"
            :table="table"
            :load-data="refreshTable"
          />
        </template>
      </BaseTable>
    </div>
  </BasePage>
</template>

<script setup>
import { debouncedWatch } from '@vueuse/core'
import { reactive, ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppointmentStore } from '@/scripts/admin/stores/appointment'
import { useUserStore } from '@/scripts/admin/stores/user'
import abilities from '@/scripts/admin/stub/abilities'
import AppointmentDropdown from '@/scripts/admin/components/dropdowns/AppointmentIndexDropdown.vue'
import AstronautIcon from '@/scripts/components/icons/empty/AstronautIcon.vue'

const appointmentStore = useAppointmentStore()
const userStore = useUserStore()

let table = ref(null)
let showFilters = ref(false)
let isFetchingInitialData = ref(true)
const { t } = useI18n()

let filters = reactive({
  search: '',
  status: '',
  type: '',
  from_date: '',
  to_date: '',
})

const statusOptions = [
  { value: '', text: t('general.all') },
  { value: 'scheduled', text: t('appointments.status_scheduled') },
  { value: 'confirmed', text: t('appointments.status_confirmed') },
  { value: 'completed', text: t('appointments.status_completed') },
  { value: 'cancelled', text: t('appointments.status_cancelled') },
  { value: 'no_show', text: t('appointments.status_no_show') },
]

const typeOptions = [
  { value: '', text: t('general.all') },
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
  { value: 'treatment', text: t('appointments.type_treatment') },
  { value: 'emergency', text: t('appointments.type_emergency') },
  { value: 'other', text: t('appointments.type_other') },
]

const showEmptyScreen = computed(
  () => !appointmentStore.totalAppointments && !isFetchingInitialData.value
)

const appointmentColumns = computed(() => {
  return [
    {
      key: 'appointment_date',
      label: t('appointments.date_time'),
      thClass: 'extra',
      tdClass: 'font-medium text-gray-900',
    },
    {
      key: 'title',
      label: t('appointments.title'),
      thClass: 'extra',
      tdClass: 'font-medium',
    },
    {
      key: 'customer',
      label: t('appointments.customer'),
      thClass: 'extra',
    },
    {
      key: 'type',
      label: t('appointments.type'),
      thClass: 'extra',
    },
    {
      key: 'status',
      label: t('appointments.status'),
      thClass: 'extra',
    },
    {
      key: 'actions',
      label: '',
      tdClass: 'text-right text-sm font-medium',
      thClass: 'extra w-20',
      sortable: false,
    },
  ]
})

onMounted(() => {
  loadAppointments()
})

debouncedWatch(
  filters,
  () => {
    refreshTable()
  },
  { debounce: 500 }
)

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

async function loadAppointments() {
  isFetchingInitialData.value = true
  await appointmentStore.fetchAppointments(filters)
  isFetchingInitialData.value = false
}

async function refreshTable() {
  await appointmentStore.fetchAppointments(filters)
}

function toggleFilter() {
  showFilters.value = !showFilters.value
}

function clearFilter() {
  filters.search = ''
  filters.status = ''
  filters.type = ''
  filters.from_date = ''
  filters.to_date = ''
}
</script>
