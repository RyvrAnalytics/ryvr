<?php

use App\Ingestion\Enrichment\Source;

it('assigns channels from referrer and campaign tags', function (string $referrer, array $query, string $channel) {
    $result = (new Source)->resolve($referrer, $query, ['example.com', 'www.example.com']);

    expect($result['channel'])->toBe($channel);
})->with([
    'direct' => ['', [], 'Direct'],
    'search' => ['https://www.google.com/', [], 'Search'],
    'search ccTLD' => ['https://www.google.co.uk/', [], 'Search'],
    'social' => ['https://t.co/abc', [], 'Social'],
    'ai assistant' => ['https://chatgpt.com/', [], 'AI assistants'],
    'ai by utm' => ['', ['utm_source' => 'chatgpt.com'], 'AI assistants'],
    'email' => ['', ['utm_medium' => 'email', 'utm_source' => 'newsletter'], 'Email'],
    'paid' => ['https://www.google.com/', ['utm_medium' => 'cpc'], 'Paid'],
    'paid by click id' => ['https://www.google.com/', ['gclid' => 'x'], 'Paid'],
    'referral' => ['https://blog.someone.dev/post', [], 'Referral'],
    'self referral is direct' => ['https://www.example.com/pricing', [], 'Direct'],
    'campaign without referrer' => ['', ['utm_source' => 'podcast'], 'Campaign'],
]);

it('drops the referrer when it is the site itself', function () {
    $result = (new Source)->resolve('https://example.com/a', [], ['example.com']);

    expect($result['referrer'])->toBe('')->and($result['referrer_domain'])->toBe('');
});
