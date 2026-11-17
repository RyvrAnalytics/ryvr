<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const items: NavItem[] = [
    { title: 'Profile', href: editProfile() },
    { title: 'Security', href: editSecurity() },
    { title: 'Appearance', href: editAppearance() },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div>
        <div class="mb-8">
            <h1 class="text-3xl font-light tracking-tight">Your account</h1>
            <nav
                class="mt-4 flex flex-wrap gap-x-5 gap-y-1 border-b border-border"
                aria-label="Account"
            >
                <Link
                    v-for="item in items"
                    :key="toUrl(item.href)"
                    :href="item.href"
                    class="-mb-px border-b-2 pb-2 text-sm"
                    :class="
                        isCurrentOrParentUrl(item.href)
                            ? 'border-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="
                        isCurrentOrParentUrl(item.href) ? 'page' : undefined
                    "
                >
                    {{ item.title }}
                </Link>
            </nav>
        </div>

        <section class="panel max-w-2xl space-y-12 p-6">
            <slot />
        </section>
    </div>
</template>
