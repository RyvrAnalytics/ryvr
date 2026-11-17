<script setup lang="ts">
defineOptions({ inheritAttrs: false });
</script>

<template>
    <!-- RYVR mark: three currents converging into one stream. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            d="M3 5.5c4 0 5 3 9 3s5-3 9-3"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
        />
        <path
            d="M3 12c4 0 5 3 9 3s5-3 9-3"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            opacity=".55"
        />
        <path
            d="M3 18.5c4 0 5 3 9 3s5-3 9-3"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            opacity=".3"
        />
    </svg>
</template>
