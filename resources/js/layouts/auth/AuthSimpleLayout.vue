<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-canvas px-4 py-8 sm:px-8">
        <Link href="/" class="self-start" aria-label="RYVR home">
            <AppLogo />
        </Link>
        <div class="flex flex-1 items-center">
            <div class="w-full max-w-sm sm:ml-[12vw]">
                <div class="mb-6">
                    <h1 class="text-3xl font-light tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <div class="panel p-6">
                    <slot />
                </div>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Privacy-first analytics. No cookies on the sites you measure.
        </p>
    </div>
</template>
