<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    siteId: string;
    domain: string;
    snippet: string | null;
}>();

const copied = ref(false);
const arrived = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

async function copy() {
    if (!props.snippet) return;
    await navigator.clipboard.writeText(props.snippet);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

// Check every few seconds; the moment a visit lands, load the real dashboard.
onMounted(() => {
    timer = setInterval(async () => {
        const response = await fetch(`/sites/${props.siteId}/realtime`, {
            headers: { Accept: 'application/json' },
        });
        if (response.ok && (await response.json()).hasData) {
            arrived.value = true;
            clearInterval(timer);
            setTimeout(() => router.reload(), 900);
        }
    }, 4000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <section class="panel max-w-3xl p-8" aria-labelledby="first-visit">
        <h2 id="first-visit" class="text-2xl font-light tracking-tight">
            Waiting for the first visit to {{ domain }}
        </h2>
        <p class="mt-2 text-sm text-muted-foreground">
            Two steps. This page updates on its own when the first visit
            arrives.
        </p>

        <ol class="mt-8 space-y-8">
            <li class="grid grid-cols-[2rem_1fr] gap-3">
                <span class="text-2xl leading-7 font-light text-river">1</span>
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        Add this line to the &lt;head&gt; of every page
                    </p>
                    <div
                        v-if="snippet"
                        class="relative mt-3 rounded-lg border border-border bg-surface p-4 pr-20 text-[13px]"
                    >
                        <code class="block break-all">{{ snippet }}</code>
                        <button
                            type="button"
                            class="absolute top-3 right-3 rounded border border-border bg-background px-2 py-0.5 text-xs hover:bg-muted"
                            @click="copy"
                        >
                            {{ copied ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <p v-else class="mt-2 text-sm text-muted-foreground">
                        Ask an admin of this site for the tracking code.
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        No cookies and no consent banner needed for basic
                        counting. Using a framework? It works in any layout
                        file.
                    </p>
                </div>
            </li>
            <li class="grid grid-cols-[2rem_1fr] gap-3">
                <span class="text-2xl leading-7 font-light text-river">2</span>
                <div>
                    <p class="text-sm font-medium">Open your site</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Visit
                        <a
                            :href="`https://${domain}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-foreground underline underline-offset-4"
                            >{{ domain }}</a
                        >
                        in a normal browser window. Visits from localhost and
                        from browsers that opted out with ?ryvr_ignore=1 are not
                        counted.
                    </p>
                </div>
            </li>
        </ol>

        <p
            class="mt-8 flex items-center gap-2 text-sm"
            :class="arrived ? 'text-up' : 'text-muted-foreground'"
            aria-live="polite"
        >
            <span class="relative flex size-2">
                <span
                    v-if="!arrived"
                    class="absolute inline-flex size-full animate-ping rounded-full bg-river/60 motion-reduce:animate-none"
                />
                <span
                    class="relative inline-flex size-2 rounded-full"
                    :class="arrived ? 'bg-up' : 'bg-river'"
                />
            </span>
            {{
                arrived
                    ? 'First visit received. Loading your dashboard.'
                    : 'Listening for the first visit'
            }}
        </p>
    </section>
</template>
