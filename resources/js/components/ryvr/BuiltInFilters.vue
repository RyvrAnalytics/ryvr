<template>
    <div class="text-sm">
        <p class="text-muted-foreground">These are always filtered out, on every site. Each one is counted on the dashboard so you can see what was removed.</p>
        <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div>
                <dt>Monitoring probes</dt>
                <dd class="text-muted-foreground">Uptime checks, Grafana and Prometheus blackbox probes, synthetic tests, health checks, link previews.</dd>
            </div>
            <div>
                <dt>AI agents</dt>
                <dd class="text-muted-foreground">Crawlers and browsing agents that fetch pages for AI models and assistants.</dd>
            </div>
            <div>
                <dt>Automated browsers</dt>
                <dd class="text-muted-foreground">Headless and remote-controlled browsers, test runners and HTTP libraries.</dd>
            </div>
            <div>
                <dt>Crawlers and spam</dt>
                <dd class="text-muted-foreground">Search and SEO crawlers, security scanners, known referrer spam, and hits from hostnames that are not this site.</dd>
            </div>
        </dl>
        <p class="mt-4 text-muted-foreground">
            To stop counting your own visits on one browser, open any page of your site with
            <code class="rounded bg-muted px-1">?ryvr_ignore=1</code> added to the address. Use <code class="rounded bg-muted px-1">?ryvr_ignore=0</code> to undo it.
        </p>
    </div>
</template>
