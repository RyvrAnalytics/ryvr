<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OrgNav from '@/components/ryvr/OrgNav.vue';

type Log = {
    id: number;
    action: string;
    actor: string;
    subject: string | null;
    metadata: Record<string, unknown> | null;
    ip: string | null;
    at: string;
};

defineProps<{
    logs: {
        data: Log[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
}>();

const time = (iso: string) =>
    new Date(iso).toLocaleString('en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
const describe = (action: string) => action.replace(/[._]/g, ' ');
const details = (meta: Record<string, unknown> | null) =>
    meta
        ? Object.entries(meta)
              .map(
                  ([k, v]) =>
                      `${k.replace(/_/g, ' ')}: ${Array.isArray(v) ? v.join(', ') : String(v)}`,
              )
              .join('; ')
        : '';
</script>

<template>
    <Head title="Audit log" />
    <OrgNav />

    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-surface text-left text-xs text-muted-foreground">
                <tr>
                    <th class="px-4 py-2.5 font-normal">When</th>
                    <th class="px-4 py-2.5 font-normal">Who</th>
                    <th class="px-4 py-2.5 font-normal">What</th>
                    <th class="px-4 py-2.5 font-normal">Details</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="log in logs.data"
                    :key="log.id"
                    class="border-t border-border align-top"
                >
                    <td
                        class="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                    >
                        {{ time(log.at) }}
                    </td>
                    <td class="px-4 py-2.5">{{ log.actor }}</td>
                    <td class="px-4 py-2.5">{{ describe(log.action) }}</td>
                    <td class="px-4 py-2.5 text-muted-foreground">
                        {{ details(log.metadata) }}
                    </td>
                </tr>
                <tr v-if="!logs.data.length">
                    <td
                        colspan="4"
                        class="px-4 py-10 text-center text-muted-foreground"
                    >
                        Nothing recorded yet.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <nav
        v-if="logs.total > logs.data.length"
        class="mt-4 flex flex-wrap gap-1 text-sm"
        aria-label="Pages"
    >
        <template v-for="link in logs.links" :key="link.label">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="rounded px-2.5 py-1"
                :class="
                    link.active
                        ? 'bg-foreground text-background'
                        : 'text-muted-foreground hover:text-foreground'
                "
                ><span v-html="link.label"
            /></Link>
        </template>
    </nav>
</template>
