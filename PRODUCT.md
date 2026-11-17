# Product

RYVR is privacy-first web analytics for people and teams who run more than one site. It counts visits without cookies, keeps bots, AI agents and monitoring probes out of the numbers, and tells you what changed before it shows you tables.

## Platform

web

## Stack

Laravel 13 (PHP 8.4), Inertia with Vue 3.5 and TypeScript, Tailwind CSS 4, PostgreSQL for accounts and settings, ClickHouse for events, Redis for the ingest buffer. Charts are hand-built SVG components. Self-hosted with Docker Compose; a hosted cloud version is planned.

## Users

- Owners of several websites (founders, studios, agencies) who want one place to see every site.
- Clients of those owners, given access to only their own sites.
- AI assistants acting for a user over MCP, reading the same numbers with the same limits.

## Product Purpose

Answer "how are my sites doing, and what changed?" in seconds, across every site at once, without collecting personal data.

## Positioning

RYVR has its own approach and must not look or read like any existing analytics product. It leads with findings (what rose, what fell, what is new, which site went quiet), treats the portfolio of sites as the main navigation, and shows how visits flow from channels to pages. Never name other analytics products anywhere in the product, code, commits or docs.

## Operating Context

Operate mode: a dashboard people check daily and scan quickly. Light theme by default with a dark theme that follows the system. Used on large desktop screens first, phones second.

## Capabilities and Constraints

- Multi-organization, multi-site; roles Owner, Admin, Analyst, Viewer, Billing; site-limited members.
- Cookieless tracker (about 1.4 KB), custom events with properties, revenue, goals.
- Built-in exclusion of crawlers, AI agents, automated browsers and uptime/monitoring probes, plus per-site and organization rules.
- REST API and MCP server scoped by API keys.
- Visitor counts across sites are a sum of sites, never de-duplicated people, and the UI must say so.

## Brand Commitments

- Simple and calm, but specific: information density over decoration.
- One accent, river blue. White panels on a quiet grey-green canvas.
- No gradients, glows, glass, pill badges, identical card grids, all-caps eyebrow labels, em dashes, or emoji in the interface.
- Copy is plain and specific: say what happened and what to do next.

## Evidence on Hand

- The owner's liked Dribbble shots (inferred direction): light dashboards, large light-weight numerals with small captions, dotted leaders between labels and values, dark tooltips, one highlighted bar or point.
- The owner rejected an earlier version for resembling an existing analytics tool's layout (metric strip, bar chart, grid of bar-backed lists).

## Product Principles

1. Findings before figures: the first thing on every view is what changed.
2. The portfolio is the product: moving between sites is one click.
3. Trust the numbers: show what was filtered out and why.
4. Privacy is structural: no cookies, no stored IPs, daily-rotating visitor hashes.
5. Same limits for people and machines: API keys and MCP never see more than their owner.

## Accessibility & Inclusion

WCAG 2.2 AA contrast, full keyboard use (charts readable with arrow keys), visible focus, reduced motion respected, numbers in tabular figures, labels that do not rely on colour alone.
