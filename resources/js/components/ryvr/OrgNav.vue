<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const can = computed(() => page.props.organization?.can ?? {});

const items = computed(() =>
    [
        { label: 'General', href: '/organization', show: true },
        { label: 'Members', href: '/organization/members', show: true },
        { label: 'Exclusions', href: '/organization/exclusions', show: true },
        {
            label: 'API and MCP',
            href: '/organization/api-keys',
            show: can.value['api.use'] || can.value['api_keys.manage'],
        },
        {
            label: 'Embedding',
            href: '/organization/embedding',
            show: can.value['embeds.manage'],
        },
        {
            label: 'Audit log',
            href: '/organization/audit',
            show: can.value['audit.view'],
        },
    ].filter((i) => i.show),
);

const isActive = (href: string) =>
    href === '/organization'
        ? page.url === '/organization'
        : page.url.startsWith(href);
</script>

<template>
    <div class="mb-8">
        <h1 class="text-3xl font-light tracking-tight">
            {{ page.props.organization?.name }}
        </h1>
        <nav
            class="mt-4 flex flex-wrap gap-x-5 gap-y-1 border-b border-border"
            aria-label="Organization"
        >
            <Link
                v-for="item in items"
                :key="item.href"
                :href="item.href"
                class="-mb-px border-b-2 pb-2 text-sm"
                :class="
                    isActive(item.href)
                        ? 'border-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="isActive(item.href) ? 'page' : undefined"
            >
                {{ item.label }}
            </Link>
        </nav>
    </div>
</template>
