<script setup lang="ts">
import {
    ArrowRight,
    Flag,
    Globe,
    Handshake,
    Link2,
    Mail,
    Megaphone,
    Monitor,
    Search,
    Smartphone,
    Sparkles,
    Tablet,
    Users,
} from '@lucide/vue';
import {
    siAndroid,
    siApple,
    siBrave,
    siFirefoxbrowser,
    siGooglechrome,
    siLinux,
    siOpera,
    siSafari,
    siSamsung,
    siUbuntu,
} from 'simple-icons';
import { computed } from 'vue';
import SiteIcon from '@/components/ryvr/SiteIcon.vue';

/**
 * A small, monochrome mark for a breakdown value: a flag for a country, a favicon for a
 * referrer, a logo for a browser or OS, a symbol for a device or channel. Renders
 * nothing when there is no honest icon for the value.
 */
const props = defineProps<{ dimension: string; value: string }>();

const BRANDS: Record<string, { path: string }> = {
    Chrome: siGooglechrome,
    'Chrome Mobile': siGooglechrome,
    Safari: siSafari,
    'Mobile Safari': siSafari,
    Firefox: siFirefoxbrowser,
    Opera: siOpera,
    Brave: siBrave,
    'Samsung Internet': siSamsung,
    macOS: siApple,
    iOS: siApple,
    iPadOS: siApple,
    Android: siAndroid,
    Linux: siLinux,
    Ubuntu: siUbuntu,
    ChromeOS: siGooglechrome,
};

const CHANNELS = {
    Search,
    Direct: ArrowRight,
    Social: Users,
    Referral: Link2,
    Email: Mail,
    Paid: Megaphone,
    'AI assistants': Sparkles,
    Campaign: Flag,
    Affiliate: Handshake,
} as const;

const DEVICES = {
    desktop: Monitor,
    mobile: Smartphone,
    tablet: Tablet,
} as const;

const kind = computed(() => {
    const { dimension, value } = props;
    if (dimension === 'country' && /^[A-Z]{2}$/.test(value)) return 'flag';
    if (dimension === 'referrer' && value) return 'site';
    if ((dimension === 'browser' || dimension === 'os') && BRANDS[value])
        return 'brand';
    if (dimension === 'browser' || dimension === 'os') return 'generic';
    if (dimension === 'device' && value in DEVICES) return 'device';
    if (dimension === 'channel' && value in CHANNELS) return 'channel';
    return null;
});
</script>

<template>
    <span
        v-if="kind === 'flag'"
        class="fi inline-block w-4 shrink-0 rounded-[2px] bg-cover"
        :class="`fi-${value.toLowerCase()}`"
        style="height: 12px"
        aria-hidden="true"
    />
    <SiteIcon v-else-if="kind === 'site'" :domain="value" :size="14" />
    <svg
        v-else-if="kind === 'brand'"
        viewBox="0 0 24 24"
        class="size-3.5 shrink-0 fill-current text-muted-foreground"
        aria-hidden="true"
    >
        <path :d="BRANDS[value].path" />
    </svg>
    <component
        :is="
            kind === 'device'
                ? DEVICES[value as keyof typeof DEVICES]
                : kind === 'channel'
                  ? CHANNELS[value as keyof typeof CHANNELS]
                  : Globe
        "
        v-else-if="kind"
        class="size-3.5 shrink-0 text-muted-foreground"
        :stroke-width="1.75"
        aria-hidden="true"
    />
</template>
