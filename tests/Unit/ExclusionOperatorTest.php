<?php

use App\Ingestion\Exclusion\Operator;

it('matches values', function (Operator $operator, string $subject, string $value, bool $expected) {
    expect($operator->matches($subject, $value))->toBe($expected);
})->with([
    [Operator::Equals, '/admin', '/ADMIN', true],
    [Operator::Contains, 'Mozilla/5.0 MyMonitor/1.0', 'mymonitor', true],
    [Operator::StartsWith, '/wp-admin/options', '/wp-admin', true],
    [Operator::StartsWith, '/blog/wp-admin', '/wp-admin', false],
    [Operator::Regex, '/account/123/settings', '^/account/\d+/', true],
    [Operator::Regex, 'anything', '(unclosed', false],
    [Operator::Cidr, '10.10.0.7', '10.10.0.0/24', true],
    [Operator::Cidr, '10.10.1.7', '10.10.0.0/24', false],
    [Operator::Cidr, '2001:db8::1', '2001:db8::/32', true],
    [Operator::Contains, 'abc', '', false],
]);

it('validates regular expressions', function () {
    expect(Operator::validRegex('^/a/\d+$'))->toBeTrue()
        ->and(Operator::validRegex('(unclosed'))->toBeFalse();
});
