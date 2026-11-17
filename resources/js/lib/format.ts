const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const whole = new Intl.NumberFormat('en');

/** 1234 -> "1.2k" above ten thousand, full digits below. */
export function formatNumber(value: number): string {
    return Math.abs(value) >= 10000
        ? compact.format(value).toLowerCase()
        : whole.format(value);
}

export function formatExact(value: number): string {
    return whole.format(value);
}

/** Seconds -> "3m 12s". */
export function formatDuration(seconds: number): string {
    if (!seconds) return '0s';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.round(seconds % 60);
    if (h) return `${h}h ${m}m`;
    if (m) return `${m}m ${s}s`;
    return `${s}s`;
}

export function formatPercent(value: number, digits = 1): string {
    return `${value.toFixed(digits).replace(/\.0+$/, '')}%`;
}

/** Relative change in percent, or null when there is nothing to compare with. */
export function change(current: number, previous: number): number | null {
    if (!previous) return current ? null : 0;
    return ((current - previous) / previous) * 100;
}

export function formatChange(value: number | null): string {
    if (value === null) return 'new';
    const rounded = Math.round(value);
    return `${rounded > 0 ? '+' : ''}${rounded}%`;
}

const countryNames =
    typeof Intl.DisplayNames === 'function'
        ? new Intl.DisplayNames(['en'], { type: 'region' })
        : null;

export function countryName(code: string): string {
    if (!code) return 'Unknown';
    try {
        return countryNames?.of(code) ?? code;
    } catch {
        return code;
    }
}

export function flag(code: string): string {
    if (!/^[A-Z]{2}$/.test(code)) return '';
    return String.fromCodePoint(
        0x1f1e6 + code.charCodeAt(0) - 65,
        0x1f1e6 + code.charCodeAt(1) - 65,
    );
}

/** "2026-11-14" -> "Nov 14", "2026-11-14 09:00" -> "9 AM", "2026-11" -> "Nov 2026". */
export function bucketLabel(bucket: string, interval: string): string {
    if (interval === 'hour') {
        const hour = Number(bucket.slice(11, 13));
        const suffix = hour < 12 ? 'AM' : 'PM';
        return `${hour % 12 || 12} ${suffix}`;
    }
    const [y, m, d] = bucket.split('-').map(Number);
    const date = new Date(Date.UTC(y, (m ?? 1) - 1, d ?? 1));
    return interval === 'month'
        ? date.toLocaleDateString('en', {
              month: 'short',
              year: 'numeric',
              timeZone: 'UTC',
          })
        : date.toLocaleDateString('en', {
              month: 'short',
              day: 'numeric',
              timeZone: 'UTC',
          });
}

export function bucketTitle(bucket: string, interval: string): string {
    if (interval === 'hour') {
        const [y, m, d] = bucket.slice(0, 10).split('-').map(Number);
        const date = new Date(Date.UTC(y, m - 1, d));
        return `${date.toLocaleDateString('en', { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' })}, ${bucketLabel(bucket, 'hour')}`;
    }
    if (interval === 'day') {
        const [y, m, d] = bucket.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'UTC',
        });
    }
    return bucketLabel(bucket, interval);
}
