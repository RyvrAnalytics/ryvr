---
name: RYVR
description: Privacy-first analytics that tells you what changed across all your sites.
colors:
    canvas: '#eef0ec'
    panel: '#ffffff'
    surface: '#fbfbfa'
    ink: '#1a1a18'
    muted: '#6f6c66'
    hairline: '#e6e4e0'
    input-border: '#dcd9d4'
    river: '#1d6a86'
    river-soft: '#cfe1e8'
    river-faint: '#eef5f7'
    stream-2: '#4f93ad'
    stream-3: '#8db8c9'
    stream-4: '#c3dbe4'
    stream-5: '#b9b5ae'
    up: '#17803d'
    down: '#b42318'
    dark-canvas: '#121312'
    dark-panel: '#1c1c1a'
    dark-ink: '#ecebe8'
    dark-river: '#5fa8c4'
typography:
    hero-number:
        fontFamily: 'IBM Plex Sans, ui-sans-serif, system-ui, sans-serif'
        fontSize: '3.75rem'
        fontWeight: 300
        lineHeight: 1
        letterSpacing: '-0.025em'
    page-title:
        fontFamily: 'IBM Plex Sans, ui-sans-serif, system-ui, sans-serif'
        fontSize: '2.25rem'
        fontWeight: 300
        lineHeight: 1.1
        letterSpacing: '-0.025em'
    body:
        fontFamily: 'IBM Plex Sans, ui-sans-serif, system-ui, sans-serif'
        fontSize: '0.875rem'
        fontWeight: 400
        lineHeight: 1.5
    caption:
        fontFamily: 'IBM Plex Sans, ui-sans-serif, system-ui, sans-serif'
        fontSize: '0.75rem'
        fontWeight: 400
        lineHeight: 1.4
rounded:
    control: '6px'
    panel: '16px'
spacing:
    row: '6px'
    gap: '16px'
    panel-padding: '20px'
components:
    panel:
        backgroundColor: '{colors.panel}'
        rounded: '{rounded.panel}'
        padding: '{spacing.panel-padding}'
    button-primary:
        backgroundColor: '{colors.ink}'
        textColor: '{colors.panel}'
        rounded: '{rounded.control}'
        padding: '6px 12px'
    period-selected:
        backgroundColor: '{colors.ink}'
        textColor: '{colors.panel}'
        rounded: '{rounded.control}'
    tooltip:
        backgroundColor: '{colors.ink}'
        textColor: '{colors.panel}'
        rounded: '{rounded.control}'
---

# Design System: RYVR

## Overview

A calm instrument for reading many websites at once. White panels sit on a grey-green canvas like stones in a riverbed; one river-blue accent marks what matters. The interface leads with findings (what changed) and treats the list of sites as the main navigation. Density comes from typography and alignment, not from boxes.

## Colors

### Primary

River blue (`#1d6a86`, dark `#5fa8c4`): the current period's line, the selected or hovered data point, links on hover, the logo mark. Never used as a large fill except the faint area under a line.

### Neutral

Canvas `#eef0ec` behind everything, panels `#ffffff`, ink `#1a1a18`, muted `#6f6c66` for labels and secondary values, hairlines `#e6e4e0`.

### Named Rules

- **One accent.** Green (`#17803d`) and red (`#b42318`) appear only on change figures; "New" findings use river blue.
- **Streams are one family.** Multi-series charts use shades of river (`stream-2` to `stream-4`) plus a warm grey for "other", never a rainbow.

## Typography

IBM Plex Sans throughout, self-hosted, with tabular figures on every number.

### Hierarchy

- Hero number: 60px, weight 300, tight tracking. One per view.
- Page title: 36px, weight 300.
- Body and list rows: 14px, weight 400; the top row of a list is 500.
- Captions, axis labels, units: 12px muted.

### Named Rules

- **Big and light, never big and bold.** Large figures are weight 300.
- **No eyebrows.** No all-caps labels, no tracked-out section titles; panel titles are 14px muted sentence case.

## Layout

A two-tier rail on the left, a 56px icon spine and a 224px sites panel (collapsed into a menu on small screens), content up to 1280px beside it, left-aligned. Top of each view: one large figure with its context, a compact dotted-leader metric list, then the trend; "What changed" sits in a 21rem column on the right. Lists sit in a three-column grid below. On screens 1536px and wider, a 17rem "Right now" column sits at the right edge: the live visitor total across all sites and every site ranked by visitors online, each linking to its live view; it stays in view while the page scrolls.

## Elevation & Depth

Flat. Panels are separated from the canvas by colour, not shadow. Only tooltips and menus carry a small shadow.

## Shapes

Panels 16px radius; controls, chips and tooltips 6px. No pills.

## Components

### Lists (breakdowns)

Label, a dotted leader, an optional share in muted 12px, then the value. Larger values are more opaque; there are no bars behind rows. The first row is medium weight. Clicking a row filters the whole view.

### Charts

- Trend: a ripple. The current period is a filled wave (river-soft) under a 2.5px river line, with two fainter echoes of the line beneath it (9px apart, fading); the previous period is a dotted muted line. Hover shows a crosshair, a filled river dot and a dark tooltip. Scale labels sit on the right; no grid.
- Portfolio: sites stacked as streams in river shades.
- Channel mix ("Where each channel lands"): one 26px strip per channel, split by landing page, with each page keeping the same colour on every row (river shades, then warm greys) and its name inside segments wider than 12%. A shared legend sits below. Clicking a channel filters the view.

### What changed

A numbered list: a large light change figure (+39%, New, Quiet, 2.4×) in a fixed left column, then a sentence that names the subject in medium weight and states the numbers plainly.

### Period control

A segmented control; the selected period is ink-filled. Less common periods sit in a "More" menu with a custom range.

### Buttons

Primary: ink fill, white text. Secondary: hairline border. Danger: red text and red hairline. No icons inside buttons unless they replace text.

### Navigation

Two tiers. A 56px spine on the canvas carries the mark and the main destinations as outlined icons (all sites, go to, organization) with the account at its foot; the active one sits on a white tile. Beside it, a white panel holds the organization switcher and the list of sites, each with a live dot and visitor count, and "Add site" at the bottom. The current site row is tinted. On small screens both tiers open together from a menu button.

## Do's and Don'ts

- Do lead with what changed, then the numbers.
- Do say "sum of sites" wherever visitors are added across sites.
- Do use dotted leaders to connect labels and values.
- Don't use bars behind list rows, a strip of equal metric cards, or a bar chart for the main trend.
- Don't use gradients, glows, glass, pills, emoji, all-caps labels or em dashes.
- Don't name other analytics products anywhere.
