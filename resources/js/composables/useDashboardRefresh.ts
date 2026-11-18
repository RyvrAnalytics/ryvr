import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { QueryState } from '@/composables/useAnalyticsQuery';

const INTERVAL = 60_000;

export function periodIncludesNow(
    query: QueryState,
    now = new Date(),
): boolean {
    if (query.period !== 'custom') {
        return [
            'today',
            '24h',
            '7d',
            '30d',
            '90d',
            '12mo',
            'month',
            'year',
        ].includes(query.period);
    }

    const parts = new Intl.DateTimeFormat('en', {
        timeZone: query.timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const date = ['year', 'month', 'day']
        .map((type) => parts.find((part) => part.type === type)?.value)
        .join('-');

    return query.from <= date && date <= query.to;
}

export function useDashboardRefresh(
    only: string[],
    enabled: () => boolean = () => true,
) {
    const lastUpdated = ref<Date | null>(null);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let refreshing = false;
    let navigating = false;
    let disposed = false;
    let cancelRefresh: (() => void) | undefined;
    const removeListeners: (() => void)[] = [];

    function schedule() {
        clearTimeout(timer);
        if (disposed || document.hidden || navigating) return;
        timer = setTimeout(
            refresh,
            Math.max(0, INTERVAL - (Date.now() - lastUpdated.value!.getTime())),
        );
    }

    function refresh() {
        clearTimeout(timer);
        if (document.hidden || navigating || refreshing || disposed) return;
        if (!enabled()) {
            timer = setTimeout(refresh, INTERVAL);
            return;
        }
        if (Date.now() - lastUpdated.value!.getTime() < INTERVAL) {
            schedule();
            return;
        }

        refreshing = true;
        router.reload({
            only,
            async: true,
            showProgress: false,
            onCancelToken: (token) => {
                cancelRefresh = () => token.cancel();
            },
            onSuccess: () => {
                lastUpdated.value = new Date();
            },
            onFinish: () => {
                refreshing = false;
                cancelRefresh = undefined;
                // Retry failures after a minute instead of immediately.
                clearTimeout(timer);
                if (!disposed && !document.hidden && !navigating) {
                    timer = setTimeout(refresh, INTERVAL);
                }
            },
        });
    }

    function visibilityChanged() {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    }

    onMounted(() => {
        lastUpdated.value = new Date();
        removeListeners.push(
            router.on('start', (event) => {
                if (event.detail.visit.async) return;
                navigating = true;
                clearTimeout(timer);
                cancelRefresh?.();
            }),
            router.on('success', () => {
                lastUpdated.value = new Date();
            }),
            router.on('finish', (event) => {
                if (event.detail.visit.async) return;
                navigating = false;
                schedule();
            }),
        );
        document.addEventListener('visibilitychange', visibilityChanged);
        schedule();
    });

    onBeforeUnmount(() => {
        disposed = true;
        clearTimeout(timer);
        cancelRefresh?.();
        document.removeEventListener('visibilitychange', visibilityChanged);
        removeListeners.forEach((remove) => remove());
    });

    return { lastUpdated };
}
