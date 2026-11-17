<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import SiteIcon from '@/components/ryvr/SiteIcon.vue';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

type Item = {
    label: string;
    hint: string;
    href: string;
    live?: number;
    domain?: string;
};

const page = usePage();
const open = ref(false);
const term = ref('');
const index = ref(0);
const input = ref<HTMLInputElement | null>(null);

const items = computed<Item[]>(() => {
    const sites = (page.props.rail ?? []).map((s) => ({
        label: s.name,
        hint: s.domain,
        href: `/sites/${s.id}`,
        live: s.live,
        domain: s.domain,
    }));
    const places: Item[] = [
        { label: 'All sites', hint: 'Every site side by side', href: '/sites' },
        {
            label: 'Add a site',
            hint: 'Get a tracking line for a new site',
            href: '/sites/new',
        },
        {
            label: 'Members',
            hint: 'Invite people and set access',
            href: '/organization/members',
        },
        {
            label: 'Exclusions',
            hint: 'Keep your own traffic and probes out',
            href: '/organization/exclusions',
        },
        {
            label: 'API and MCP',
            hint: 'Keys for scripts and AI assistants',
            href: '/organization/api-keys',
        },
        {
            label: 'Organization settings',
            hint: 'Name, timezone, groups',
            href: '/organization',
        },
    ];
    return [...sites, ...places];
});

const results = computed(() => {
    const q = term.value.trim().toLowerCase();
    if (!q) return items.value;
    return items.value.filter(
        (i) =>
            i.label.toLowerCase().includes(q) ||
            i.hint.toLowerCase().includes(q),
    );
});

watch(results, () => (index.value = 0));
watch(open, async (value) => {
    if (value) {
        term.value = '';
        index.value = 0;
        await nextTick();
        input.value?.focus();
    }
});

function go(item: Item | undefined) {
    if (!item) return;
    open.value = false;
    router.visit(item.href);
}

function onKey(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        index.value = Math.min(results.value.length - 1, index.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        index.value = Math.max(0, index.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(results.value[index.value]);
    }
}

function onGlobalKey(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));

defineExpose({ show: () => (open.value = true) });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[20%] max-w-lg translate-y-0 gap-0 overflow-hidden p-0"
        >
            <DialogTitle class="sr-only">Go to a site or page</DialogTitle>
            <input
                ref="input"
                v-model="term"
                type="text"
                class="w-full border-b border-border bg-transparent px-4 py-3.5 text-base outline-none placeholder:text-muted-foreground"
                placeholder="Go to a site or page"
                role="combobox"
                aria-controls="quick-results"
                :aria-activedescendant="
                    results[index] ? `quick-${index}` : undefined
                "
                aria-expanded="true"
                @keydown="onKey"
            />
            <ul
                id="quick-results"
                role="listbox"
                class="max-h-80 overflow-y-auto p-1.5"
            >
                <li
                    v-for="(item, i) in results"
                    :id="`quick-${i}`"
                    :key="item.href"
                    role="option"
                    :aria-selected="i === index"
                    class="flex cursor-pointer items-baseline gap-3 rounded-lg px-3 py-2 text-sm"
                    :class="i === index ? 'bg-muted' : ''"
                    @mouseenter="index = i"
                    @click="go(item)"
                >
                    <SiteIcon
                        v-if="item.domain"
                        :domain="item.domain"
                        class="self-center"
                    />
                    <span class="font-medium">{{ item.label }}</span>
                    <span
                        class="min-w-0 flex-1 truncate text-muted-foreground"
                        >{{ item.hint }}</span
                    >
                    <span v-if="item.live" class="text-xs text-up"
                        >{{ item.live }} online</span
                    >
                </li>
                <li
                    v-if="!results.length"
                    class="px-3 py-6 text-sm text-muted-foreground"
                >
                    Nothing matches “{{ term }}”.
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
