<script setup lang="ts">
import { computed, ref } from 'vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { PERIODS } from '@/composables/useAnalyticsQuery';

const props = defineProps<{ period: string; from: string; to: string }>();
const emit = defineEmits<{ change: [period: string, from?: string, to?: string] }>();

const QUICK = ['today', '7d', '30d', '90d', '12mo'];
const quick = PERIODS.filter((p) => QUICK.includes(p.value));
const more = PERIODS.filter((p) => !QUICK.includes(p.value));

const customOpen = ref(false);
const customFrom = ref(props.from);
const customTo = ref(props.to);

const moreLabel = computed(() => {
    if (props.period === 'custom') return `${props.from} to ${props.to}`;
    return more.find((p) => p.value === props.period)?.label ?? 'More';
});

function applyCustom() {
    if (customFrom.value && customTo.value) {
        emit('change', 'custom', customFrom.value, customTo.value);
        customOpen.value = false;
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-md border border-border p-0.5" role="radiogroup" aria-label="Period">
            <button
                v-for="p in quick"
                :key="p.value"
                type="button"
                role="radio"
                :aria-checked="period === p.value"
                :title="p.label"
                class="rounded px-2.5 py-1 text-sm transition-colors"
                :class="period === p.value ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'"
                @click="emit('change', p.value)"
            >
                {{ p.short }}
            </button>
        </div>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="rounded-md border border-border px-2.5 py-1 text-sm"
                    :class="!QUICK.includes(period) ? 'bg-foreground text-background border-foreground' : 'text-muted-foreground hover:text-foreground'"
                >
                    {{ moreLabel }}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem v-for="p in more" :key="p.value" @select="emit('change', p.value)">
                    {{ p.label }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="customOpen = true">Custom range…</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <form v-if="customOpen" class="flex items-center gap-2 text-sm" @submit.prevent="applyCustom">
            <label class="sr-only" for="period-from">From</label>
            <input id="period-from" v-model="customFrom" type="date" class="rounded-md border border-input px-2 py-1" required />
            <span class="text-muted-foreground">to</span>
            <label class="sr-only" for="period-to">To</label>
            <input id="period-to" v-model="customTo" type="date" :min="customFrom" class="rounded-md border border-input px-2 py-1" required />
            <button type="submit" class="rounded-md bg-foreground px-2.5 py-1 text-background">Apply</button>
            <button type="button" class="text-muted-foreground hover:text-foreground" @click="customOpen = false">Cancel</button>
        </form>
    </div>
</template>
