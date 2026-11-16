<?php

use App\Ingestion\Exclusion\BotDetector;

dataset('humans', [
    'chrome windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'safari iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    'firefox linux' => 'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0',
    'edge mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
    'samsung android' => 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
    'pinterest in-app' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 [Pinterest/Android]',
    'opera' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/116.0.0.0',
]);

it('keeps real browsers', function (string $ua) {
    expect((new BotDetector)->classify($ua))->toBeNull();
})->with('humans');

it('classifies non-human traffic by kind', function (string $ua, string $reason) {
    expect((new BotDetector)->classify($ua))->toBe($reason);
})->with([
    ['Blackbox Exporter/0.25.0', 'monitor'],
    ['Grafana/11.3.0', 'monitor'],
    ['Mozilla/5.0 (compatible; Grafana Synthetic-Monitoring/1.0)', 'monitor'],
    ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', 'monitor'],
    ['Prometheus/2.53.0', 'monitor'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'ai_agent'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'ai_agent'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ai_agent'],
    ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'ai_agent'],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36', 'automation'],
    ['python-requests/2.32.3', 'automation'],
    ['curl/8.7.1', 'automation'],
    ['Go-http-client/2.0', 'automation'],
    ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'bot'],
    ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'bot'],
    ['', 'bot'],
    ['something', 'bot'],
]);

it('flags referrer spam domains and their subdomains', function () {
    $detector = new BotDetector;

    expect($detector->isSpamReferrer('semalt.com'))->toBeTrue()
        ->and($detector->isSpamReferrer('www.semalt.com'))->toBeTrue()
        ->and($detector->isSpamReferrer('news.ycombinator.com'))->toBeFalse();
});
