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
        <div class="flex flex-1 flex-col px-4 py-8 sm:px-8 lg:px-12">
            <Link href="/" class="self-start" aria-label="RYVR home">
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
            <p class="text-xs text-muted-foreground lg:hidden">
                Privacy-first analytics. No cookies on the sites you measure.
            </p>
        </div>

        <aside
            class="relative hidden w-[42%] max-w-[760px] shrink-0 overflow-hidden bg-[#040c16] lg:block"
        >
            <img
                src="/brand/auth-current-1360.webp"
                srcset="
                    /brand/auth-current-720.webp   720w,
                    /brand/auth-current-1360.webp 1360w
                "
                sizes="42vw"
                width="1360"
                height="2048"
                alt=""
                class="absolute inset-0 h-full w-full object-cover object-bottom"
            />
            <p
                class="absolute top-12 right-12 left-12 max-w-sm text-2xl leading-snug font-light tracking-tight text-white/90"
            >
                See where your traffic flows.
                <span class="mt-3 block text-sm font-normal text-white/55">
                    Privacy-first analytics. No cookies on the sites you
                    measure.
                </span>
            </p>
        </aside>
    </div>
</template>
