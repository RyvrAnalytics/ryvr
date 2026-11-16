<?php

namespace App\Ingestion\Enrichment;

/**
 * Where a visit came from: referrer domain, campaign tags and a channel.
 */
class Source
{
    private const SEARCH = ['google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.com', 'ecosia.org',
        'search.brave.com', 'startpage.com', 'qwant.com', 'naver.com', 'seznam.cz', 'kagi.com', 'search.yahoo.', 'sogou.com'];

    private const SOCIAL = ['facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 't.co', 'x.com', 'linkedin.com',
        'lnkd.in', 'reddit.com', 'pinterest.', 'tiktok.com', 'youtube.com', 'youtu.be', 'threads.net', 'bsky.app',
        'mastodon.', 'news.ycombinator.com', 'tumblr.com', 'vk.com', 'quora.com', 'discord.com', 'telegram.org',
        'whatsapp.com', 'snapchat.com', 'medium.com', 'producthunt.com'];

    private const AI = ['chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'gemini.google.com',
        'copilot.microsoft.com', 'bard.google.com', 'you.com', 'poe.com', 'phind.com', 'chat.mistral.ai',
        'deepseek.com', 'meta.ai', 'grok.com', 'kagi.com/assistant'];

    private const EMAIL = ['mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.com',
        'mail.proton.me', 'app.fastmail.com', 'mail.zoho.com'];

    /**
     * @param  array<string, string>  $query  the page URL's query parameters
     * @param  list<string>  $ownHosts  hostnames that count as the site itself
     * @return array{referrer: string, referrer_domain: string, channel: string, utm_source: string, utm_medium: string, utm_campaign: string, utm_content: string, utm_term: string}
     */
    public function resolve(string $referrer, array $query, array $ownHosts): array
    {
        $utm = [];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) {
            $utm['utm_'.$k] = mb_substr(trim((string) ($query['utm_'.$k] ?? '')), 0, 200);
        }
        // Common shorthand used by newsletters and link shorteners.
        if ($utm['utm_source'] === '' && isset($query['ref'])) {
            $utm['utm_source'] = mb_substr(trim((string) $query['ref']), 0, 200);
        }

        $domain = '';
        if ($referrer !== '') {
            $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if ($host !== '' && ! in_array($host, array_map(fn ($h) => preg_replace('/^www\./', '', $h), $ownHosts), true)) {
                $domain = $host;
            }
        }

        // Ad click ids mark paid traffic even when campaign tags are missing.
        $hasGclid = isset($query['gclid']) || isset($query['msclkid']) || isset($query['gbraid']) || isset($query['wbraid']);

        return [
            'referrer' => $domain === '' ? '' : mb_substr($referrer, 0, 1024),
            'referrer_domain' => $domain,
            'channel' => $this->channel($domain, strtolower($utm['utm_source']), strtolower($utm['utm_medium']), $hasGclid),
        ] + $utm;
    }

    public function channel(string $domain, string $source, string $medium, bool $paidClickId = false): string
    {
        if ($paidClickId || preg_match('/^(cpc|ppc|paid|paidsearch|paid_search|cpm|cpv|display|banner|retargeting)/', $medium)) {
            return 'Paid';
        }
        if (in_array($medium, ['email', 'e-mail', 'newsletter'], true) || $this->matches($domain, self::EMAIL)) {
            return 'Email';
        }
        if ($this->matches($domain, self::AI) || $this->matches($source, self::AI) || in_array($source, ['chatgpt', 'chatgpt.com', 'perplexity', 'claude'], true)) {
            return 'AI assistants';
        }
        if (in_array($medium, ['social', 'social-network', 'social-media', 'sm'], true) || $this->matches($domain, self::SOCIAL)) {
            return 'Social';
        }
        if (in_array($medium, ['organic', 'seo'], true) || $this->matches($domain, self::SEARCH)) {
            return 'Search';
        }
        if (in_array($medium, ['affiliate', 'partner'], true)) {
            return 'Affiliate';
        }
        if ($domain !== '' || $medium === 'referral') {
            return 'Referral';
        }
        if ($source !== '' || $medium !== '') {
            return 'Campaign';
        }

        return 'Direct';
    }

    /**
     * @param  list<string>  $list
     */
    private function matches(string $domain, array $list): bool
    {
        if ($domain === '') {
            return false;
        }
        foreach ($list as $entry) {
            if (str_ends_with($entry, '.')) {
                if (str_starts_with($domain, $entry) || str_contains($domain, '.'.$entry)) {
                    return true;
                }
            } elseif ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                return true;
            }
        }

        return false;
    }
}
