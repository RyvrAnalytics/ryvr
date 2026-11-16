<?php

namespace App\Ingestion\Exclusion;

/**
 * Built-in detection of non-human traffic from the user agent.
 *
 * Patterns are grouped so the drop counters can say *why* traffic was filtered:
 * AI agents, uptime and monitoring probes, automation tools, and general crawlers.
 */
class BotDetector
{
    /** AI crawlers and agents that fetch pages on behalf of a model or assistant. */
    private const AI_AGENTS = [
        'gptbot', 'chatgpt-user', 'chatgpt-agent', 'oai-searchbot', 'openai',
        'claudebot', 'claude-user', 'claude-searchbot', 'claude-web', 'anthropic-ai',
        'perplexitybot', 'perplexity-user', 'google-extended', 'googleother', 'google-cloudvertexbot',
        'gemini-deep-research', 'bytespider', 'ccbot', 'amazonbot', 'novaact', 'applebot',
        'meta-externalagent', 'meta-externalfetcher', 'facebookbot', 'cohere-ai', 'cohere-training-data-crawler',
        'diffbot', 'youbot', 'mistralai-user', 'duckassistbot', 'ai2bot', 'timpibot', 'omgili',
        'imagesiftbot', 'petalbot', 'kangaroo bot', 'webzio', 'phindbot', 'iaskspider', 'manus',
        'firecrawl', 'browserbase', 'browser-use', 'skyvern', 'operator', 'agentql', 'tavily',
    ];

    /** Uptime checks, synthetic monitoring and observability probes. */
    private const MONITORS = [
        'blackbox exporter', 'prometheus', 'grafana', 'synthetic-monitoring', 'k6/', 'uptimerobot',
        'pingdom', 'statuscake', 'site24x7', 'betteruptime', 'better stack', 'uptime-kuma', 'uptimekuma',
        'datadog', 'newrelicpinger', 'new relic synthetics', 'checkly', 'ohdear', 'oh dear', 'zabbix',
        'nagios', 'icinga', 'monit/', 'freshping', 'hetrixtools', 'updown.io', 'cron-job.org',
        'healthchecks', 'elastic-heartbeat', 'heartbeat/', 'gatus', 'kube-probe', 'googlehc',
        'elb-healthchecker', 'amazon-route53-health-check', 'azure traffic manager', 'cloudflare-healthchecks',
        'pagespeed', 'chrome-lighthouse', 'lighthouse', 'gtmetrix', 'webpagetest', 'sitebulb', 'dareboost',
        'forge', 'envoyer', 'stripe/', 'twilio', 'slackbot', 'discordbot', 'telegrambot',
        'skypeuripreview', 'linkedinbot', 'twitterbot', 'facebookexternalhit', 'pinterestbot', 'embedly',
    ];

    /** Headless browsers, test drivers and HTTP libraries. */
    private const AUTOMATION = [
        'headlesschrome', 'headless', 'phantomjs', 'puppeteer', 'playwright', 'selenium', 'webdriver',
        'chromedriver', 'geckodriver', 'cypress', 'electron/', 'jsdom', 'htmlunit', 'splash', 'zombie',
        'python-requests', 'python-urllib', 'python-httpx', 'aiohttp', 'scrapy', 'curl/', 'wget/',
        'go-http-client', 'okhttp', 'java/', 'apache-httpclient', 'libwww-perl', 'node-fetch', 'axios/',
        'undici', 'got (', 'guzzlehttp', 'symfony httpclient', 'ruby', 'faraday', 'httpie', 'postmanruntime',
        'insomnia', 'powershell', 'winhttp', 'reqwest', 'dart:io', 'nutch', 'httrack',
    ];

    /** Generic crawler markers. Checked last: broad words. */
    private const CRAWLERS = [
        'bot', 'crawl', 'spider', 'slurp', 'scraper', 'archiver', 'fetcher', 'preview', 'scanner',
        'checker', 'monitor', 'validator', 'feed', 'indexer', 'mediapartners', 'adsbot', 'bingpreview',
        'yandex', 'baidu', 'sogou', 'seznam', 'qwant', 'ahrefs', 'semrush', 'mj12', 'dotbot', 'screaming frog',
        'dataforseo', 'serpstat', 'barkrowler', 'zoominfo', 'censys', 'shodan', 'nmap', 'masscan', 'nuclei',
        'zgrab', 'expanse', 'internet-measurement', 'leakix',
    ];

    private const SPAM_REFERRERS = [
        'semalt.com', 'buttons-for-website.com', 'darodar.com', 'ilovevitaly.com', 'priceg.com',
        'hulfingtonpost.com', 'best-seo-offer.com', 'free-social-buttons.com', 'get-free-traffic-now.com',
        'social-buttons.com', 'trafficmonetize.com', 'event-tracking.com', 'floating-share-buttons.com',
    ];

    public function classify(string $userAgent): ?string
    {
        $ua = strtolower(trim($userAgent));

        if ($ua === '') {
            return TrafficFilter::REASON_BOT;
        }

        if ($this->containsAny($ua, self::AI_AGENTS)) {
            return TrafficFilter::REASON_AI_AGENT;
        }

        if ($this->containsAny($ua, self::MONITORS)) {
            return TrafficFilter::REASON_MONITOR;
        }

        if ($this->containsAny($ua, self::AUTOMATION)) {
            return TrafficFilter::REASON_AUTOMATION;
        }

        if ($this->containsAny($ua, self::CRAWLERS)) {
            return TrafficFilter::REASON_BOT;
        }

        // Real browsers identify as Mozilla/5.0 (or Opera on very old builds).
        if (strlen($ua) < 25 || (! str_starts_with($ua, 'mozilla/') && ! str_starts_with($ua, 'opera/'))) {
            return TrafficFilter::REASON_BOT;
        }

        return null;
    }

    public function isSpamReferrer(string $referrerDomain): bool
    {
        $domain = strtolower($referrerDomain);
        foreach (self::SPAM_REFERRERS as $spam) {
            if ($domain === $spam || str_ends_with($domain, '.'.$spam)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
