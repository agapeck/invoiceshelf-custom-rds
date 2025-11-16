<template>
  <img 
    :src="logoSrc" 
    alt="Logo" 
    class="h-auto max-w-full"
  />
</template>

<script setup>
import loginLogo from '../../../images/rds-logo-login.png'
import { computed } from 'vue'

const props = defineProps({
  isWhite: {
    type: Boolean,
    default: false,
  },
  darkColor: {
    type: String,
    default: 'rgba(var(--color-primary-500), var(--tw-text-opacity))',
  },
  lightColor: {
    type: String,
    default: 'rgba(var(--color-primary-400), var(--tw-text-opacity))',
  },
})

// Determine which logo to use based on color props
const logoSrc = computed(() => {
  // If both light and dark colors are white, use white logo
  if (props.lightColor === 'white' && props.darkColor === 'white') {
    return '/img/logo-white.png'
  }
  
  // If isWhite prop is explicitly set, use white logo
  if (props.isWhite) {
    return '/img/logo-white.png'
  }
  
  // Default to InvoiceShelf login logo asset (imported)
  return loginLogo
})
</script>
