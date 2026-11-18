import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { QueryState } from './useAnalyticsQuery';
import { periodIncludesNow, useDashboardRefresh } from './useDashboardRefresh';

type RefreshOptions = {
    onSuccess: () => void;
    onFinish: () => void;
    onCancelToken: (token: { cancel: () => void }) => void;
};
type VisitEvent = { detail: { visit: { async: boolean } } };

const { reload, listeners, mounted, unmounted } = vi.hoisted(() => ({
    reload: vi.fn<(options: RefreshOptions) => void>(),
    listeners: new Map<string, (event: VisitEvent) => void>(),
    mounted: [] as (() => void)[],
    unmounted: [] as (() => void)[],
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        reload,
        on: (name: string, callback: (event: VisitEvent) => void) => {
            listeners.set(name, callback);
            return () => listeners.delete(name);
        },
    },
}));
vi.mock('vue', async (importOriginal) => ({
    ...(await importOriginal<typeof import('vue')>()),
    onMounted: (callback: () => void) => mounted.push(callback),
    onBeforeUnmount: (callback: () => void) => unmounted.push(callback),
}));

class TestDocument extends EventTarget {
    hidden = false;
}

let document: TestDocument;

function mount(enabled?: () => boolean) {
    const result = useDashboardRefresh(['series', 'panels'], enabled);
    mounted.forEach((callback) => callback());
    return result;
}

function visibility(hidden: boolean) {
    document.hidden = hidden;
    document.dispatchEvent(new Event('visibilitychange'));
}

function visitEvent(name: string) {
    listeners.get(name)?.({ detail: { visit: { async: false } } });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-11-17T17:00:00Z'));
    reload.mockReset();
    listeners.clear();
    mounted.length = 0;
    unmounted.length = 0;
    document = new TestDocument();
    vi.stubGlobal('document', document);
});

afterEach(() => {
    unmounted.forEach((callback) => callback());
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('dashboard refresh', () => {
    it('partially reloads data props every minute without showing progress', () => {
        const { lastUpdated } = mount();
        vi.advanceTimersByTime(59_999);
        expect(reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['series', 'panels'],
                showProgress: false,
            }),
        );
        const options = reload.mock.calls[0][0];
        options.onSuccess();
        options.onFinish();
        expect(lastUpdated.value?.toISOString()).toBe(
            '2026-11-17T17:01:00.000Z',
        );
        vi.advanceTimersByTime(60_000);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('rechecks whether the selected period is eligible', () => {
        let enabled = false;
        mount(() => enabled);
        vi.advanceTimersByTime(120_000);
        expect(reload).not.toHaveBeenCalled();
        enabled = true;
        vi.advanceTimersByTime(60_000);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('pauses while hidden and refreshes once immediately when overdue', () => {
        mount();
        visibility(true);
        vi.advanceTimersByTime(180_000);
        expect(reload).not.toHaveBeenCalled();
        visibility(false);
        visibility(false);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('waits for the remaining interval when returning before a minute', () => {
        mount();
        vi.advanceTimersByTime(20_000);
        visibility(true);
        vi.advanceTimersByTime(20_000);
        visibility(false);
        vi.advanceTimersByTime(19_999);
        expect(reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('does not overlap a slow refresh', () => {
        mount();
        vi.advanceTimersByTime(180_000);
        visibility(false);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('waits for period and filter visits to finish', () => {
        mount();
        visitEvent('start');
        vi.advanceTimersByTime(120_000);
        visibility(false);
        expect(reload).not.toHaveBeenCalled();
        visitEvent('success');
        visitEvent('finish');
        vi.advanceTimersByTime(59_999);
        expect(reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('cancels an older refresh when a period or filter visit starts', () => {
        mount();
        vi.advanceTimersByTime(60_000);
        const options = reload.mock.calls[0][0];
        const cancel = vi.fn(() => options.onFinish());
        options.onCancelToken({ cancel });
        visitEvent('start');
        expect(cancel).toHaveBeenCalledOnce();
        vi.advanceTimersByTime(120_000);
        expect(reload).toHaveBeenCalledTimes(1);
        visitEvent('finish');
        vi.advanceTimersByTime(0);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('retains the timestamp and waits a minute before retrying a failure', () => {
        const { lastUpdated } = mount();
        vi.advanceTimersByTime(60_000);
        reload.mock.calls[0][0].onFinish();
        expect(lastUpdated.value?.toISOString()).toBe(
            '2026-11-17T17:00:00.000Z',
        );
        vi.advanceTimersByTime(59_999);
        expect(reload).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('removes listeners, cancels pending work and stops timers on unmount', () => {
        mount();
        vi.advanceTimersByTime(60_000);
        const options = reload.mock.calls[0][0];
        const cancel = vi.fn(() => options.onFinish());
        options.onCancelToken({ cancel });
        unmounted.forEach((callback) => callback());
        unmounted.length = 0;
        expect(cancel).toHaveBeenCalledOnce();
        expect(listeners.size).toBe(0);
        visibility(false);
        vi.advanceTimersByTime(120_000);
        expect(reload).toHaveBeenCalledTimes(1);
    });
});

describe('period includes now', () => {
    const query: QueryState = {
        period: 'today',
        from: '2026-11-17',
        to: '2026-11-17',
        timezone: 'America/Toronto',
        interval: 'hour',
        filters: {},
    };

    it.each(['today', '24h', '7d', '30d', '90d', '12mo', 'month', 'year'])(
        'keeps the relative %s period refreshing across date boundaries',
        (period) => {
            expect(
                periodIncludesNow(
                    { ...query, period },
                    new Date('2026-11-18T17:00:00Z'),
                ),
            ).toBe(true);
        },
    );

    it.each(['yesterday', 'last_month'])(
        'does not refresh the historical %s period',
        (period) => {
            expect(periodIncludesNow({ ...query, period })).toBe(false);
        },
    );

    it('uses inclusive dates in the reporting timezone for custom ranges', () => {
        const custom = { ...query, period: 'custom' };
        expect(
            periodIncludesNow(custom, new Date('2026-11-17T04:59:00Z')),
        ).toBe(false);
        expect(
            periodIncludesNow(custom, new Date('2026-11-17T05:00:00Z')),
        ).toBe(true);
        expect(
            periodIncludesNow(custom, new Date('2026-11-18T04:59:00Z')),
        ).toBe(true);
        expect(
            periodIncludesNow(custom, new Date('2026-11-18T05:00:00Z')),
        ).toBe(false);
    });
});
