import { router } from '@inertiajs/vue3';

export type QueryState = {
    period: string;
    from: string;
    to: string;
    filters: Record<string, string>;
    interval: string;
    timezone: string;
};

export const PERIODS: { value: string; label: string; short: string }[] = [
    { value: 'today', label: 'Today', short: 'Today' },
    { value: 'yesterday', label: 'Yesterday', short: 'Yesterday' },
    { value: '24h', label: 'Last 24 hours', short: '24h' },
    { value: '7d', label: 'Last 7 days', short: '7d' },
    { value: '30d', label: 'Last 30 days', short: '30d' },
    { value: '90d', label: 'Last 90 days', short: '90d' },
    { value: 'month', label: 'This month', short: 'Month' },
    { value: 'last_month', label: 'Last month', short: 'Last month' },
    { value: '12mo', label: 'Last 12 months', short: '12mo' },
    { value: 'year', label: 'This year', short: 'Year' },
];

export const DIMENSION_LABELS: Record<string, string> = {
    page: 'Page',
    entry_page: 'Entry page',
    exit_page: 'Exit page',
    hostname: 'Hostname',
    referrer: 'Referrer',
    channel: 'Channel',
    utm_source: 'Source',
    utm_medium: 'Medium',
    utm_campaign: 'Campaign',
    utm_content: 'Content',
    utm_term: 'Term',
    country: 'Country',
    region: 'Region',
    city: 'City',
    browser: 'Browser',
    os: 'OS',
    device: 'Device',
    language: 'Language',
    event: 'Event',
};

/** Query-string params for the current state, optionally changed. */
export function queryParams(state: QueryState, changes: Partial<{ period: string; from: string; to: string; filters: Record<string, string> }> = {}): Record<string, string> {
    const period = changes.period ?? state.period;
    const filters = changes.filters ?? state.filters;
    const params: Record<string, string> = { period };

    if (period === 'custom') {
        params.from = changes.from ?? state.from;
        params.to = changes.to ?? state.to;
    }
    for (const [key, value] of Object.entries(filters)) {
        params[`f[${key}]`] = value;
    }

    return params;
}

export function useAnalyticsQuery(state: () => QueryState) {
    const visit = (changes: Parameters<typeof queryParams>[1]) => {
        router.get(window.location.pathname, queryParams(state(), changes), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return {
        setPeriod: (period: string, from?: string, to?: string) => visit({ period, from, to }),
        addFilter: (dimension: string, value: string) => {
            // Entry and exit pages filter the page column.
            const key = dimension === 'entry_page' || dimension === 'exit_page' ? 'page' : dimension;
            visit({ filters: { ...state().filters, [key]: value } });
        },
        removeFilter: (dimension: string) => {
            const filters = { ...state().filters };
            delete filters[dimension];
            visit({ filters });
        },
        clearFilters: () => visit({ filters: {} }),
    };
}
