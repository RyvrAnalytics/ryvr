import { useMediaQuery } from '@vueuse/core';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';

type SidebarRealtime = {
    online: number;
    minutes: { minute: string; visitors: number; pageviews: number }[];
    pages: { value: string; visitors: number }[];
    sources: { value: string; visitors: number }[];
};

export function useSidebarRealtime(
    siteId: Readonly<Ref<string | null>>,
    enabled: () => boolean,
) {
    // Match the live column's 2xl breakpoint; smaller layouts do not show it.
    const visible = useMediaQuery('(min-width: 96rem)');
    const data = ref<SidebarRealtime | null>(null);
    let active: AbortController | undefined;
    let timer: ReturnType<typeof setInterval> | undefined;
    let mounted = false;

    function cancel() {
        active?.abort();
        active = undefined;
    }

    async function refresh() {
        if (
            !mounted ||
            document.hidden ||
            !visible.value ||
            !enabled() ||
            !siteId.value ||
            active
        )
            return;

        const request = new AbortController();
        active = request;
        try {
            const response = await fetch(`/sites/${siteId.value}/now`, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            const result = response.ok ? await response.json() : null;
            if (!request.signal.aborted) data.value = result;
        } catch {
            if (!request.signal.aborted) data.value = null;
        } finally {
            if (active === request) active = undefined;
        }
    }

    const stopWatching = watch([siteId, visible, enabled], () => {
        cancel();
        data.value = null;
        void refresh();
    });

    function visibilityChanged() {
        if (document.hidden) cancel();
        else void refresh();
    }

    onMounted(() => {
        mounted = true;
        void refresh();
        timer = setInterval(() => void refresh(), 15_000);
        document.addEventListener('visibilitychange', visibilityChanged);
    });
    onBeforeUnmount(() => {
        mounted = false;
        stopWatching();
        cancel();
        clearInterval(timer);
        document.removeEventListener('visibilitychange', visibilityChanged);
    });

    return data;
}
