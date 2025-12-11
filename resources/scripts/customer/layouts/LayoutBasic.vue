<template>
  <div v-if="isAppLoaded" class="h-full">
    <NotificationRoot />

    <SiteHeader />

    <!-- <SiteSidebar /> -->

    <main class="mt-16 pb-16 h-screen overflow-y-auto min-h-0">
      <router-view />
    </main>
  </div>

  <!-- <BaseGlobalLoader v-else /> -->
</template>

<script setup>
import { onMounted, computed } from 'vue'
import SiteHeader from '@/scripts/customer/layouts/partials/TheSiteHeader.vue'
import NotificationRoot from '@/scripts/components/notifications/NotificationRoot.vue'
import { useGlobalStore } from '@/scripts/customer/stores/global'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/scripts/customer/stores/auth'
import { useIdleLogout } from '@/scripts/composables/useIdleLogout'

const globalStore = useGlobalStore()
const route = useRoute()
const authStore = useAuthStore()

// Initialize idle logout detection (30 minutes) with customer logout
useIdleLogout({
  timeoutMinutes: 30,
  logoutFn: () => authStore.logout(route.params.company),
  useWindowStore: true,
})

const isAppLoaded = computed(() => {
  return globalStore.isAppLoaded
})

loadData()

async function loadData() {
  await globalStore.bootstrap(route.params.company)
}
</script>
