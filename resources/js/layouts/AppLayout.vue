<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { Toaster } from '@/components/ui/sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';

const page = usePage();
const user = computed(() => page.props.auth.user);
const organization = computed(() => page.props.organization);
const organizations = computed(() => page.props.organizations ?? []);
const { getInitials } = useInitials();

const nav = computed(() => {
    if (!organization.value) return [];
    return [
        { label: 'Sites', href: '/sites', match: '/sites' },
        {
            label: 'Organization',
            href: '/organization',
            match: '/organization',
        },
    ];
});

const isActive = (match: string) => page.url.startsWith(match);

function switchTo(id: string) {
    router.post(`/organizations/${id}/switch`);
}
</script>

<template>
    <div class="min-h-dvh bg-background">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-4 focus:z-50 focus:rounded focus:bg-foreground focus:px-3 focus:py-1.5 focus:text-background"
        >
            Skip to content
        </a>
        <header class="border-b border-border">
            <div
                class="mx-auto flex h-14 max-w-6xl items-center gap-6 px-4 sm:px-6"
            >
                <Link href="/sites" class="shrink-0" aria-label="RYVR home">
                    <AppLogo />
                </Link>

                <DropdownMenu v-if="organization">
                    <DropdownMenuTrigger
                        class="flex min-w-0 items-center gap-1 rounded px-1.5 py-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <span class="truncate">{{ organization.name }}</span>
                        <ChevronDown class="size-3.5 shrink-0" />
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

                <nav class="flex h-full items-stretch gap-5" aria-label="Main">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center border-b-2 text-sm transition-colors"
                        :class="
                            isActive(item.match)
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        :aria-current="
                            isActive(item.match) ? 'page' : undefined
                        "
                    >
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto">
                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger
                            class="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-medium text-foreground"
                            :aria-label="`Account menu for ${user.name}`"
                        >
                            {{ getInitials(user.name) }}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <slot />
        </main>
        <Toaster position="bottom-right" />
    </div>
</template>
