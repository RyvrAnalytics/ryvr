# Accessibility

RYVR targets WCAG 2.2 Level AA for the dashboard and embeds. Work is still in
progress.

## What is in place

- Keyboard access to navigation and standard controls, and a skip-to-content link
  in the dashboard.
- Visible focus styles on dashboard controls, with shared colour tokens for text,
  backgrounds and focus rings in light and dark themes.
- Reduced-motion rules for dashboard animations and transitions, and for the live
  indicator in embeds.
- Screen-reader labels on the dashboard's stacked and live charts. These describe
  totals or peaks. The embed trend chart has a label with its date range
  and peak.
- Data tables in the sites list and expanded breakdowns. The embed trend widget
  includes a visually hidden table of each data point for screen readers.

## Known gaps

Charts are visual first. Stacked and live chart details rely on pointer hover, are
not reachable by keyboard, and their tooltip changes are not announced to screen
readers. Dashboard
charts do not all have a table for every data point. The embed trend table lacks
column headers.

Some clickable breakdown rows do not support keyboard activation. Colour contrast
and keyboard and screen-reader access still need a complete review.

## Report a problem

Open a GitHub issue with the [Accessibility template](https://github.com/RyvrAnalytics/ryvr/issues/new?template=accessibility.yml).
Include the page or widget, what you tried, what happened and what you expected.
Add your browser, operating system and assistive technology, if used. Screenshots
or a recording can help; remove personal data and secrets before sharing.
