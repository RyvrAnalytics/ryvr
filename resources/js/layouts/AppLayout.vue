<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Menu, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import QuickSwitcher from '@/components/ryvr/QuickSwitcher.vue';
import SiteIcon from '@/components/ryvr/SiteIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import { useSidebarRealtime } from '@/composables/useSidebarRealtime';

const page = usePage();
const user = computed(() => page.props.auth.user);
const organization = computed(() => page.props.organization);
const organizations = computed(() => page.props.organizations ?? []);
const rail = computed(
    () =>
        (page.props.rail ?? []) as {
            id: string;
            name: string;
            domain: string;
            live: number;
        }[],
);
const promo = computed(() => page.props.promo ?? null);
const { getInitials } = useInitials();

const open = ref(false);
const switcher = ref<{ show: () => void } | null>(null);
const isMac =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.platform);
watch(
    () => page.url,
    () => (open.value = false),
);

const path = computed(() => page.url.split('?')[0]);
const isSite = (id: string) => path.value.startsWith(`/sites/${id}`);
const totalLive = computed(() => rail.value.reduce((a, s) => a + s.live, 0));

// The "right now" list shows only the busiest sites so the column fits the viewport.
const RIGHT_NOW_LIMIT = 8;
const busiest = computed(() =>
    [...rail.value]
        .sort((a, b) => b.live - a.live || a.name.localeCompare(b.name))
        .slice(0, RIGHT_NOW_LIMIT),
);
const moreSites = computed(() =>
    Math.max(0, rail.value.length - RIGHT_NOW_LIMIT),
);

// Keep the period when moving between sites from the rail.
const period = computed(() => {
    const match = page.url.match(/[?&]period=([^&]+)/);
    return match ? `?period=${match[1]}` : '';
});

function switchTo(id: string) {
    router.post(`/organizations/${id}/switch`);
}
const sideSiteId = computed(
    () => path.value.match(/^\/sites\/([0-9a-f-]{36})/)?.[1] ?? null,
);
const sideSite = computed(
    () => rail.value.find((s) => s.id === sideSiteId.value) ?? null,
);
const sideNow = useSidebarRealtime(
    sideSiteId,
    () => !!organization.value && rail.value.length > 0,
);
</script>

<template>
    <div class="min-h-dvh bg-canvas lg:flex">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-4 focus:z-50 focus:rounded focus:bg-foreground focus:px-3 focus:py-1.5 focus:text-background"
        >
            Skip to content
        </a>

        <!-- Small screens: a bar with a menu button -->
        <div class="flex h-14 items-center gap-3 px-4 lg:hidden">
            <button
                type="button"
                class="rounded p-1.5 hover:bg-card"
                :aria-expanded="open"
                aria-controls="rail"
                aria-label="Open navigation"
                @click="open = !open"
            >
                <component :is="open ? X : Menu" class="size-5" />
            </button>
            <Link href="/sites"><AppLogo /></Link>
        </div>

        <aside
            id="rail"
            class="lg:sticky lg:top-0 lg:flex lg:h-dvh lg:shrink-0"
            :class="open ? 'flex' : 'hidden'"
        >
            <div
                class="flex w-14 flex-col items-center gap-1.5 py-5 text-muted-foreground"
            >
                <Link href="/sites" class="mb-4" aria-label="RYVR home">
                    <AppLogoIcon class="h-[18px] w-9" />
                </Link>
                <Link
                    href="/sites"
                    title="All sites"
                    aria-label="All sites"
                    class="flex size-10 items-center justify-center rounded-xl hover:text-foreground"
                    :class="path === '/sites' ? 'bg-card text-foreground' : ''"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        class="size-5"
                        aria-hidden="true"
                    >
                        <path
                            d="M3 7c3 0 4 2 9 2s6-2 9-2M3 13c3 0 4 2 9 2s6-2 9-2M3 19c3 0 4 2 9 2"
                        />
                    </svg>
                </Link>
                <button
                    v-if="organization"
                    type="button"
                    :title="`Go to (${isMac ? '⌘' : 'Ctrl'} K)`"
                    aria-label="Go to a site or page"
                    class="flex size-10 items-center justify-center rounded-xl hover:text-foreground"
                    @click="switcher?.show()"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        class="size-5"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="6" />
                        <path d="m20 20-4.5-4.5" />
                    </svg>
                </button>
                <Link
                    v-if="organization"
                    href="/organization"
                    title="Organization"
                    aria-label="Organization"
                    class="flex size-10 items-center justify-center rounded-xl hover:text-foreground"
                    :class="
                        path.startsWith('/organization')
                            ? 'bg-card text-foreground'
                            : ''
                    "
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="size-5"
                        aria-hidden="true"
                    >
                        <path d="M4 20V8l8-4 8 4v12M9 20v-5h6v5M4 20h16" />
                    </svg>
                </Link>
                <div class="mt-auto">
                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger
                            class="flex size-9 items-center justify-center rounded-full bg-card text-[11px] font-medium text-foreground"
                            :aria-label="`Account menu for ${user.name}`"
                        >
                            {{ getInitials(user.name) }}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            side="right"
                            class="w-56"
                        >
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div
                v-if="organization"
                class="m-2 ml-0 flex w-56 flex-col rounded-2xl bg-card"
            >
                <DropdownMenu>
                    <DropdownMenuTrigger
                        class="flex w-full items-center gap-2 border-b border-border px-4 py-4 text-left"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{
                                organization.name
                            }}</span>
                            <span
                                class="block text-xs text-muted-foreground capitalize"
                                >{{ organization.role }}</span
                            >
                        </span>
                        <ChevronsUpDown
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-60">
                        <DropdownMenuLabel
                            class="text-xs font-normal text-muted-foreground"
                            >Organizations</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="org in organizations"
                            :key="org.id"
                            @select="switchTo(org.id)"
                        >
                            <span class="flex-1 truncate">{{ org.name }}</span>
                            <Check
                                v-if="org.id === organization.id"
                                class="size-4"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <Link href="/start?new=1" class="cursor-pointer"
                                ><Plus class="size-4" />New organization</Link
                            >
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <div class="flex items-baseline justify-between px-4 pt-4 pb-2">
                    <span class="text-xs text-muted-foreground">Sites</span>
                    <span v-if="totalLive" class="text-xs text-up"
                        >{{ totalLive }} online</span
                    >
                </div>
                <nav
                    class="min-h-0 flex-1 overflow-y-auto px-2"
                    aria-label="Sites"
                >
                    <Link
                        v-for="site in rail"
                        :key="site.id"
                        :href="`/sites/${site.id}${period}`"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm"
                        :class="
                            isSite(site.id)
                                ? 'bg-muted font-medium'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-current="isSite(site.id) ? 'page' : undefined"
                        :title="site.domain"
                    >
                        <SiteIcon :domain="site.domain" :size="14" />
                        <span class="min-w-0 flex-1 truncate">{{
                            site.name
                        }}</span>
                        <span v-if="site.live" class="text-xs text-up">{{
                            site.live
                        }}</span>
                    </Link>
                </nav>
                <Link
                    v-if="organization.can['projects.manage']"
                    href="/sites/new"
                    class="m-2 flex items-center gap-2 rounded-lg px-2 py-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <Plus class="size-3.5" /> Add site
                </Link>
            </div>
        </aside>

        <main
            id="main"
            class="flex min-w-0 flex-1 gap-6 px-4 pt-2 pb-12 sm:px-6 lg:px-8 lg:pt-8"
        >
            <div class="min-w-0 flex-1">
                <slot />
            </div>
            <aside
                v-if="organization && rail.length"
                class="sticky top-8 hidden max-h-[calc(100vh-4rem)] w-[17rem] shrink-0 self-start 2xl:flex 2xl:flex-col"
                :class="promo ? 'h-[calc(100vh-4rem)]' : ''"
                aria-labelledby="live-column-title"
            >
                <section
                    aria-labelledby="live-column-title"
                    class="flex min-h-0 flex-col"
                >
                    <div class="mb-6 flex h-16 shrink-0 items-end">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg bg-card px-3 py-2 text-left text-sm text-muted-foreground hover:text-foreground"
                            @click="switcher?.show()"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linecap="round"
                                class="size-4"
                                aria-hidden="true"
                            >
                                <circle cx="11" cy="11" r="6" />
                                <path d="m20 20-4.5-4.5" />
                            </svg>
                            <span class="flex-1">Search sites and pages</span>
                            <kbd
                                class="rounded border border-border px-1.5 text-[11px]"
                                >{{ isMac ? '⌘' : 'Ctrl' }} K</kbd
                            >
                        </button>
                    </div>
                    <div class="panel min-h-0 overflow-y-auto">
                        <div class="flex items-baseline justify-between">
                            <h2
                                id="live-column-title"
                                class="text-sm text-muted-foreground"
                            >
                                {{ sideSite ? 'Pulse' : 'Right now' }}
                            </h2>
                            <span
                                class="text-xs"
                                :class="
                                    (sideNow?.online ?? totalLive)
                                        ? 'text-up'
                                        : 'text-muted-foreground'
                                "
                                >{{
                                    sideNow
                                        ? `${sideNow.online} online`
                                        : `${totalLive} online`
                                }}</span
                            >
                        </div>
                        <template v-if="sideNow">
                            <div
                                class="mt-4 flex h-[5.5rem] items-end gap-[2px]"
                                role="img"
                                :aria-label="`Visitors per minute over the last 30 minutes on ${sideSite?.name}`"
                            >
                                <div
                                    v-for="(m, i) in sideNow.minutes"
                                    :key="m.minute"
                                    class="flex-1 rounded-t-[2px]"
                                    :class="
                                        i === sideNow.minutes.length - 1
                                            ? 'bg-river'
                                            : 'bg-river-soft'
                                    "
                                    :style="{
                                        height: `${Math.max(6, (m.visitors / Math.max(1, ...sideNow.minutes.map((x) => x.visitors))) * 100)}%`,
                                    }"
                                    :title="`${m.minute}: ${m.visitors} visitors`"
                                />
                            </div>
                            <p
                                class="mt-1 flex justify-between text-[11px] text-muted-foreground"
                            >
                                <span>30 min ago</span><span>now</span>
                            </p>
                            <h3 class="mt-5 text-xs text-muted-foreground">
                                Reading
                            </h3>
                            <ul class="mt-1">
                                <li
                                    v-for="row in sideNow.pages.slice(0, 4)"
                                    :key="row.value"
                                    class="flex items-baseline gap-2 py-1 text-sm"
                                >
                                    <span
                                        class="max-w-[75%] min-w-0 truncate"
                                        >{{ row.value }}</span
                                    >
                                    <span
                                        class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                        aria-hidden="true"
                                    />
                                    <span class="tabular-nums">{{
                                        row.visitors
                                    }}</span>
                                </li>
                                <li
                                    v-if="!sideNow.pages.length"
                                    class="py-1 text-sm text-muted-foreground"
                                >
                                    Nobody right now
                                </li>
                            </ul>
                            <h3 class="mt-4 text-xs text-muted-foreground">
                                Arriving from
                            </h3>
                            <ul class="mt-1">
                                <li
                                    v-for="row in sideNow.sources.slice(0, 4)"
                                    :key="row.value"
                                    class="flex items-baseline gap-2 py-1 text-sm"
                                >
                                    <span
                                        class="max-w-[75%] min-w-0 truncate"
                                        >{{ row.value || 'Direct' }}</span
                                    >
                                    <span
                                        class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                        aria-hidden="true"
                                    />
                                    <span class="tabular-nums">{{
                                        row.visitors
                                    }}</span>
                                </li>
                                <li
                                    v-if="!sideNow.sources.length"
                                    class="py-1 text-sm text-muted-foreground"
                                >
                                    No arrivals in 30 minutes
                                </li>
                            </ul>
                        </template>
                        <ul v-else class="mt-4">
                            <li v-for="site in busiest" :key="site.id">
                                <Link
                                    :href="`/sites/${site.id}/now`"
                                    class="group flex items-baseline gap-2 py-1.5 text-sm"
                                >
                                    <SiteIcon
                                        :domain="site.domain"
                                        :size="14"
                                        class="self-center"
                                    />
                                    <span
                                        class="max-w-[60%] min-w-0 truncate group-hover:text-river"
                                        >{{ site.name }}</span
                                    >
                                    <span
                                        class="min-w-3 flex-1 translate-y-[-3px] border-b border-dotted border-muted-foreground/40"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="tabular-nums"
                                        :class="
                                            site.live
                                                ? 'text-up'
                                                : 'text-muted-foreground'
                                        "
                                        >{{ site.live }}</span
                                    >
                                </Link>
                            </li>
                            <li v-if="moreSites">
                                <Link
                                    href="/sites"
                                    class="mt-1 block py-1.5 text-sm text-muted-foreground hover:text-river"
                                    >+{{ moreSites }} more</Link
                                >
                            </li>
                        </ul>
                    </div>
                </section>
                <div v-if="promo" class="mt-auto shrink-0 pt-6">
                    <p class="mb-2 text-xs text-muted-foreground">Sponsored</p>
                    <iframe
                        :src="promo.url"
                        title="Sponsored"
                        width="100%"
                        :height="promo.height"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        class="block w-full rounded-2xl border-0 bg-card"
                        :style="{ height: `${promo.height}px` }"
                    />
                </div>
            </aside>
        </main>
        <QuickSwitcher v-if="organization" ref="switcher" />
        <Toaster position="bottom-right" />
    </div>
</template>
