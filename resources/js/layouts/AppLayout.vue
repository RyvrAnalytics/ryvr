<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Menu, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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

// Keep the period when moving between sites from the rail.
const period = computed(() => {
    const match = page.url.match(/[?&]period=([^&]+)/);
    return match ? `?period=${match[1]}` : '';
});

function switchTo(id: string) {
    router.post(`/organizations/${id}/switch`);
}
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
                <Link
                    href="/sites"
                    class="mb-4 text-river"
                    aria-label="RYVR home"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        class="size-6"
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
            class="min-w-0 flex-1 px-4 pt-2 pb-12 sm:px-6 lg:px-8 lg:pt-8"
        >
            <div class="max-w-7xl">
                <slot />
            </div>
        </main>
        <QuickSwitcher v-if="organization" ref="switcher" />
        <Toaster position="bottom-right" />
    </div>
</template>
