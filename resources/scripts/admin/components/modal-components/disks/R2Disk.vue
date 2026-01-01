<template>
  <form @submit.prevent="submitData">
    <div class="px-8 py-6">
      <BaseInputGrid>
        <BaseInputGroup
          :label="$t('settings.disk.name')"
          :error="
            v$.r2DiskConfigData.name.$error &&
            v$.r2DiskConfigData.name.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model="diskStore.r2DiskConfigData.name"
            type="text"
            name="name"
            :invalid="v$.r2DiskConfigData.name.$error"
            @input="v$.r2DiskConfigData.name.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.driver')"
          :error="
            v$.r2DiskConfigData.selected_driver.$error &&
            v$.r2DiskConfigData.selected_driver.$errors[0].$message
          "
          required
        >
          <BaseMultiselect
            v-model="selected_driver"
            :invalid="v$.r2DiskConfigData.selected_driver.$error"
            value-prop="value"
            :options="disks"
            searchable
            label="name"
            :can-deselect="false"
            track-by="name"
            @update:modelValue="onChangeDriver(data)"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_endpoint')"
          :error="
            v$.r2DiskConfigData.endpoint.$error &&
            v$.r2DiskConfigData.endpoint.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.endpoint"
            type="text"
            name="endpoint"
            placeholder="https://<account-id>.r2.cloudflarestorage.com"
            :invalid="v$.r2DiskConfigData.endpoint.$error"
            @input="v$.r2DiskConfigData.endpoint.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_key')"
          :error="
            v$.r2DiskConfigData.key.$error &&
            v$.r2DiskConfigData.key.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.key"
            type="text"
            name="key"
            placeholder="Ex. AKIAIOSFODNN7EXAMPLE"
            :invalid="v$.r2DiskConfigData.key.$error"
            @input="v$.r2DiskConfigData.key.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_secret')"
          :error="
            v$.r2DiskConfigData.secret.$error &&
            v$.r2DiskConfigData.secret.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.secret"
            type="text"
            name="secret"
            placeholder="Ex. ********"
            :invalid="v$.r2DiskConfigData.secret.$error"
            @input="v$.r2DiskConfigData.secret.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_region')"
          :error="
            v$.r2DiskConfigData.region.$error &&
            v$.r2DiskConfigData.region.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.region"
            type="text"
            name="region"
            placeholder="auto"
            :invalid="v$.r2DiskConfigData.region.$error"
            @input="v$.r2DiskConfigData.region.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_bucket')"
          :error="
            v$.r2DiskConfigData.bucket.$error &&
            v$.r2DiskConfigData.bucket.$errors[0].$message
          "
          required
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.bucket"
            type="text"
            name="bucket"
            placeholder="Ex. my-bucket"
            :invalid="v$.r2DiskConfigData.bucket.$error"
            @input="v$.r2DiskConfigData.bucket.$touch()"
          />
        </BaseInputGroup>

        <BaseInputGroup
          :label="$t('settings.disk.r2_root')"
          :error="
            v$.r2DiskConfigData.root.$error &&
            v$.r2DiskConfigData.root.$errors[0].$message
          "
        >
          <BaseInput
            v-model.trim="diskStore.r2DiskConfigData.root"
            type="text"
            name="root"
            placeholder="Ex. /backups/"
            :invalid="v$.r2DiskConfigData.root.$error"
            @input="v$.r2DiskConfigData.root.$touch()"
          />
        </BaseInputGroup>
      </BaseInputGrid>
      <div v-if="!isDisabled" class="flex items-center mt-6">
        <div class="relative flex items-center w-12">
          <BaseSwitch v-model="set_as_default" class="flex" />
        </div>
        <div class="ml-4 right">
          <p class="p-0 mb-1 text-base leading-snug text-black box-title">
            {{ $t('settings.disk.is_default') }}
          </p>
        </div>
      </div>
    </div>
    <slot :disk-data="{ isLoading, submitData }" />
  </form>
</template>

<script>
import { useDiskStore } from '@/scripts/admin/stores/disk'
import { useModalStore } from '@/scripts/stores/modal'
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import useVuelidate from '@vuelidate/core'
import { required, url, helpers } from '@vuelidate/validators'
export default {
  props: {
    isEdit: {
      type: Boolean,
      require: true,
      default: false,
    },
    loading: {
      type: Boolean,
      require: true,
      default: false,
    },
    disks: {
      type: Array,
      require: true,
      default: Array,
    },
  },

  emits: ['submit', 'onChangeDisk'],

  setup(props, { emit }) {
    const diskStore = useDiskStore()
    const modalStore = useModalStore()
    const { t } = useI18n()

    let isLoading = ref(false)
    let set_as_default = ref(false)
    let selected_disk = ref('')
    let is_current_disk = ref(null)

    const selected_driver = computed({
      get: () => diskStore.selected_driver,
      set: (value) => {
        diskStore.selected_driver = value
        diskStore.r2DiskConfigData.selected_driver = value
      },
    })

    const rules = computed(() => {
      return {
        r2DiskConfigData: {
          root: {}, // Optional
          key: {
            required: helpers.withMessage(t('validation.required'), required),
          },
          secret: {
            required: helpers.withMessage(t('validation.required'), required),
          },
          region: {
            required: helpers.withMessage(t('validation.required'), required),
          },
          endpoint: {
            required: helpers.withMessage(t('validation.required'), required),
            url: helpers.withMessage(t('validation.invalid_url'), url),
          },
          bucket: {
            required: helpers.withMessage(t('validation.required'), required),
          },
          selected_driver: {
            required: helpers.withMessage(t('validation.required'), required),
          },

          name: {
            required: helpers.withMessage(t('validation.required'), required),
          },
        },
      }
    })

    const v$ = useVuelidate(
      rules,
      computed(() => diskStore)
    )

    onBeforeUnmount(() => {
      diskStore.r2DiskConfigData = {
        name: null,
        selected_driver: 'r2',
        key: null,
        secret: null,
        region: 'auto',
        bucket: null,
        endpoint: null,
        root: null,
      }
    })

    loadData()

    async function loadData() {
      isLoading.value = true
      let data = reactive({
        disk: 'r2',
      })

      if (props.isEdit) {
        Object.assign(
          diskStore.r2DiskConfigData,
          JSON.parse(modalStore.data.credentials)
        )
        set_as_default.value = modalStore.data.set_as_default

        if (set_as_default.value) {
          is_current_disk.value = true
        }
      } else {
        let diskData = await diskStore.fetchDiskEnv(data)
        Object.assign(diskStore.r2DiskConfigData, diskData.data)
      }
      selected_disk.value = props.disks.find((v) => v.value == 'r2')
      isLoading.value = false
    }

    const isDisabled = computed(() => {
      return props.isEdit && set_as_default.value && is_current_disk.value
        ? true
        : false
    })

    async function submitData() {
      v$.value.r2DiskConfigData.$touch()
      if (v$.value.r2DiskConfigData.$invalid) {
        return true
      }

      let data = {
        credentials: diskStore.r2DiskConfigData,
        name: diskStore.r2DiskConfigData.name,
        driver: selected_disk.value.value,
        set_as_default: set_as_default.value,
      }
      emit('submit', data)
      return false
    }

    function onChangeDriver() {
      emit('onChangeDisk', diskStore.r2DiskConfigData.selected_driver)
    }

    return {
      v$,
      diskStore,
      selected_driver,
      isLoading,
      set_as_default,
      selected_disk,
      is_current_disk,
      loadData,
      submitData,
      onChangeDriver,
      isDisabled,
    }
  },
}
</script>
