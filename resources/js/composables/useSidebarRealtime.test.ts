import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import { useSidebarRealtime } from './useSidebarRealtime';

const { mounted, unmounted } = vi.hoisted(() => ({
    mounted: [] as (() => void)[],
    unmounted: [] as (() => void)[],
}));
vi.mock('vue', async (importOriginal) => ({
    ...(await importOriginal<typeof import('vue')>()),
    onMounted: (callback: () => void) => mounted.push(callback),
    onBeforeUnmount: (callback: () => void) => unmounted.push(callback),
}));
vi.mock('@vueuse/core', () => ({ useMediaQuery: () => visible }));

class TestDocument extends EventTarget {
    hidden = false;
}

let document: TestDocument;
let visible = ref(false);
const fetch = vi.fn<typeof globalThis.fetch>();
const payload = (online: number) => ({
    online,
    minutes: [],
    pages: [],
    sources: [],
});
const response = (online: number) =>
    ({ ok: true, json: async () => payload(online) }) as Response;
const flush = async () => {
    await nextTick();
    await vi.advanceTimersByTimeAsync(0);
};

function mount(site = ref<string | null>('site-a'), enabled = ref(true)) {
    const data = useSidebarRealtime(site, () => enabled.value);
    mounted.forEach((callback) => callback());
    return data;
}

beforeEach(() => {
    vi.useFakeTimers();
    mounted.length = 0;
    unmounted.length = 0;
    visible = ref(false);
    document = new TestDocument();
    fetch.mockReset().mockResolvedValue(response(7));
    vi.stubGlobal('fetch', fetch);
    vi.stubGlobal('document', document);
});
afterEach(() => {
    unmounted.forEach((callback) => callback());
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('sidebar realtime reads', () => {
    it('does no hidden-column reads and starts fresh when the column becomes visible', async () => {
        const data = mount();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetch).not.toHaveBeenCalled();

        visible.value = true;
        await flush();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(data.value?.online).toBe(7);

        visible.value = false;
        await flush();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(data.value).toBeNull();
    });

    it('never overlaps a slow request with a polling request', async () => {
        visible.value = true;
        let complete!: (value: Response) => void;
        fetch.mockReturnValueOnce(
            new Promise((resolve) => {
                complete = resolve;
            }),
        );
        const data = mount();
        await vi.advanceTimersByTimeAsync(45_000);
        expect(fetch).toHaveBeenCalledTimes(1);
        complete(response(4));
        await flush();
        expect(data.value?.online).toBe(4);
        await vi.advanceTimersByTimeAsync(15_000);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('cancels the old site and rejects its late response after navigation', async () => {
        visible.value = true;
        let complete!: (value: Response) => void;
        fetch.mockReturnValueOnce(
            new Promise((resolve) => {
                complete = resolve;
            }),
        );
        const site = ref<string | null>('site-a');
        const data = mount(site);
        const oldSignal = fetch.mock.calls[0][1]!.signal!;

        site.value = 'site-b';
        await flush();
        expect(oldSignal.aborted).toBe(true);
        expect(fetch.mock.calls[1][0]).toBe('/sites/site-b/now');
        expect(data.value?.online).toBe(7);

        complete(response(99));
        await flush();
        expect(data.value?.online).toBe(7);
    });

    it('pauses background work, refreshes on return and cancels on unmount', async () => {
        visible.value = true;
        mount();
        await flush();
        document.hidden = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(30_000);
        expect(fetch).toHaveBeenCalledTimes(1);

        fetch.mockReturnValueOnce(new Promise(() => {}));
        document.hidden = false;
        document.dispatchEvent(new Event('visibilitychange'));
        expect(fetch).toHaveBeenCalledTimes(2);
        const signal = fetch.mock.calls[1][1]!.signal!;
        unmounted.forEach((callback) => callback());
        expect(signal.aborted).toBe(true);
        await vi.advanceTimersByTimeAsync(30_000);
        document.dispatchEvent(new Event('visibilitychange'));
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('requires an enabled current site rather than reading the portfolio', async () => {
        visible.value = true;
        const site = ref<string | null>(null);
        const enabled = ref(false);
        mount(site, enabled);
        await flush();
        site.value = 'site-a';
        await flush();
        expect(fetch).not.toHaveBeenCalled();
        enabled.value = true;
        await flush();
        expect(fetch.mock.calls[0][0]).toBe('/sites/site-a/now');
        site.value = null;
        await flush();
        await vi.advanceTimersByTimeAsync(30_000);
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
