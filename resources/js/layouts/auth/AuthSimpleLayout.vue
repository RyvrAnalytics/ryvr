<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh bg-canvas">
        <aside
            class="relative hidden w-[42%] max-w-[760px] shrink-0 overflow-hidden bg-[#0e2530] lg:block"
        >
            <img
                src="/brand/auth-river-1360.webp"
                srcset="
                    /brand/auth-river-720.webp   720w,
                    /brand/auth-river-1360.webp 1360w
                "
                sizes="42vw"
                width="1360"
                height="2048"
                alt=""
                class="absolute inset-0 h-full w-full object-cover dark:brightness-75"
            />
            <Link
                href="/"
                class="absolute top-10 left-10"
                aria-label="RYVR home"
            >
                <img
                    src="/brand/ryvr-logo-384.webp?v=20261118-spacing"
                    width="1565"
                    height="330"
                    alt="RYVR"
                    class="h-6 w-auto"
                />
            </Link>
        </aside>

        <div class="flex flex-1 flex-col px-4 py-8 sm:px-8">
            <Link href="/" class="self-start lg:hidden" aria-label="RYVR home">
                <AppLogo />
            </Link>
            <div class="flex flex-1 items-center justify-center">
                <div class="w-full max-w-sm">
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
    </div>
</template>
