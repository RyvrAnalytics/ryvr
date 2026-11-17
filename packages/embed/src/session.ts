// Token handling and API calls. The token lives in memory only (never in storage or a URL)
// and is refreshed through the customer's fetchToken() shortly before it expires.

export type TokenFetcher = () => Promise<string>;

export type Claims = {
    exp?: number;
    ryvr?: { origins?: string[]; sites?: string[]; widgets?: string[] };
};

export class EmbedError extends Error {
    constructor(
        public code: string,
        message: string,
        public status = 0,
    ) {
        super(message);
    }
}

/** Seconds before expiry at which a token is replaced. */
const REFRESH_MARGIN = 60;

export function decodePart<T>(part: string): T | null {
    try {
        const b64 = part.replace(/-/g, '+').replace(/_/g, '/');
        const json = decodeURIComponent(
            Array.from(atob(b64))
                .map(
                    (c) => '%' + ('0' + c.charCodeAt(0).toString(16)).slice(-2),
                )
                .join(''),
        );
        return JSON.parse(json) as T;
    } catch {
        return null;
    }
}

export class Session {
    private token = '';
    private exp = 0;
    private pending: Promise<string> | null = null;
    private dead = false;
    claims: Claims = {};
    kid: string;

    constructor(
        public readonly host: string,
        kid: string | undefined,
        private readonly fetchToken: TokenFetcher,
    ) {
        this.kid = kid ?? '';
    }

    /** A token valid for at least REFRESH_MARGIN more seconds. */
    async get(force = false): Promise<string> {
        if (this.dead)
            throw new EmbedError('destroyed', 'Embed was destroyed.');
        const now = Date.now() / 1000;
        if (!force && this.token && this.exp - now > REFRESH_MARGIN) {
            return this.token;
        }
        if (!this.pending) {
            this.pending = this.refresh().finally(() => {
                this.pending = null;
            });
        }
        return this.pending;
    }

    private async refresh(): Promise<string> {
        let token: unknown;
        try {
            token = await this.fetchToken();
        } catch {
            throw new EmbedError(
                'token_unavailable',
                'Could not get an embed token.',
            );
        }
        const parts = typeof token === 'string' ? token.split('.') : [];
        const header =
            parts.length === 3 ? decodePart<{ kid?: string }>(parts[0]) : null;
        const claims = parts.length === 3 ? decodePart<Claims>(parts[1]) : null;
        if (!header?.kid || !claims) {
            throw new EmbedError(
                'token_malformed',
                'fetchToken() did not return an embed token.',
            );
        }
        if (this.kid && header.kid !== this.kid) {
            throw new EmbedError(
                'key_mismatch',
                'The embed token was signed with a different key.',
            );
        }
        this.kid = header.kid;
        this.claims = claims;
        this.token = token as string;
        this.exp = claims.exp ?? 0;
        return this.token;
    }

    /** GET an embed API path with the bearer token. Retries once after a 401. */
    async api<T>(
        path: string,
        params: Record<string, string | undefined> = {},
        retried = false,
    ): Promise<T> {
        const token = await this.get(retried);
        const query = new URLSearchParams();
        for (const [k, v] of Object.entries(params)) {
            if (v !== undefined && v !== '') query.set(k, v);
        }
        const qs = query.toString();
        const res = await fetch(
            `${this.host}/embed/v1/${encodeURIComponent(this.kid)}/${path}${qs ? '?' + qs : ''}`,
            {
                headers: {
                    Authorization: 'Bearer ' + token,
                    Accept: 'application/json',
                },
                credentials: 'omit',
                mode: 'cors',
                cache: 'no-store',
                referrerPolicy: 'no-referrer',
            },
        );
        if (res.status === 401 && !retried) {
            return this.api<T>(path, params, true);
        }
        const body = (await res.json().catch(() => null)) as {
            data?: T;
            error?: { code?: string; message?: string };
        } | null;
        if (!res.ok || !body || body.data === undefined) {
            throw new EmbedError(
                body?.error?.code ?? 'request_failed',
                body?.error?.message ?? 'The request failed.',
                res.status,
            );
        }
        return body.data;
    }

    destroy(): void {
        this.dead = true;
        this.token = '';
        this.exp = 0;
        this.claims = {};
    }
}

/** Whether a concrete origin matches an allowed pattern (https://host or https://*.host). */
export function originMatches(origin: string, pattern: string): boolean {
    if (!pattern.includes('://*.')) return origin === pattern;
    const [scheme, rest] = pattern.split('://*.');
    const prefix = scheme + '://';
    if (!origin.startsWith(prefix)) return false;
    const host = origin.slice(prefix.length);
    return host.length > rest.length + 1 && host.endsWith('.' + rest);
}
