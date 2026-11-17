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
            class="flex-col gap-6 px-4 pb-6 lg:sticky lg:top-0 lg:flex lg:h-dvh lg:w-64 lg:shrink-0 lg:pt-6"
            :class="open ? 'flex' : 'hidden'"
        >
            <Link
                href="/sites"
                class="hidden px-2 lg:block"
                aria-label="RYVR home"
            >
                <AppLogo />
            </Link>

            <button
                v-if="organization"
                type="button"
                class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm text-muted-foreground hover:text-foreground"
                @click="switcher?.show()"
            >
                <span>Go to…</span>
                <kbd class="rounded border border-border px-1.5 text-[11px]"
                    >{{ isMac ? '⌘' : 'Ctrl' }} K</kbd
                >
            </button>

            <DropdownMenu v-if="organization">
                <DropdownMenuTrigger
                    class="flex w-full items-center gap-2 rounded-lg bg-card px-3 py-2 text-left text-sm"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{
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
                        <Link href="/start?new=1" class="cursor-pointer">
                            <Plus class="size-4" />
                            New organization
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <nav
                v-if="organization"
                class="min-h-0 flex-1 overflow-y-auto"
                aria-label="Sites"
            >
                <Link
                    href="/sites"
                    class="flex items-center justify-between rounded-lg px-2 py-1.5 text-sm"
                    :class="
                        path === '/sites'
                            ? 'bg-card font-medium'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="path === '/sites' ? 'page' : undefined"
                >
                    <span>All sites</span>
                    <span v-if="totalLive" class="text-xs text-up"
                        >{{ totalLive }} now</span
                    >
                </Link>

                <ul class="mt-3 space-y-px">
                    <li v-for="site in rail" :key="site.id">
                        <Link
                            :href="`/sites/${site.id}${period}`"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm"
                            :class="
                                isSite(site.id)
                                    ? 'bg-card font-medium'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            :aria-current="isSite(site.id) ? 'page' : undefined"
                            :title="site.domain"
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="
                                    site.live
                                        ? 'bg-up'
                                        : 'bg-muted-foreground/30'
                                "
                                :aria-label="
                                    site.live
                                        ? `${site.live} online`
                                        : undefined
                                "
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                site.name
                            }}</span>
                            <span v-if="site.live" class="text-xs text-up">{{
                                site.live
                            }}</span>
                        </Link>
                    </li>
                </ul>
                <Link
                    v-if="organization.can['projects.manage']"
                    href="/sites/new"
                    class="mt-2 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <Plus class="size-3.5" /> Add site
                </Link>
            </nav>

            <div class="mt-auto space-y-1 border-t border-border pt-4">
                <Link
                    v-if="organization"
                    href="/organization"
                    class="block rounded-lg px-2 py-1.5 text-sm"
                    :class="
                        path.startsWith('/organization')
                            ? 'bg-card font-medium'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                >
                    Organization
                </Link>
                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger
                        class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-muted-foreground hover:text-foreground"
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-card text-[11px] font-medium text-foreground"
                        >
                            {{ getInitials(user.name) }}
                        </span>
                        <span class="truncate">{{ user.name }}</span>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
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
