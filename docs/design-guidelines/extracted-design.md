# Overwatch UI Extraction

## Reference Inputs

- `/Users/vietlubu/Downloads/nightwatch-ui/sample.html`
- `/Users/vietlubu/Downloads/nightwatch-ui/*.png`

## Visual Direction

- Base aesthetic: terminal-inspired monitoring console.
- Structural reference: Laravel Nightwatch screenshots.
- Render direction: dark Catppuccin Mocha shell, mono typography, dense observability layout.
- Mood: calm, high-signal, operator-focused, less glossy dashboard and more "debug console with polish".

## Tokens

- Background stack: `#11111b`, `#181825`, `#1e1e2e`
- Panel surfaces: `#313244`, `#45475a`, `#585b70`
- Text: `#cdd6f4`, secondary `#bac2de`, muted `#a6adc8`
- Accents:
  - primary `#89b4fa`
  - highlight `#cba6f7`
  - success `#a6e3a1`
  - warning `#f9e2af`
  - danger `#f38ba8`
  - data cyan `#89dceb`

## Layout Patterns

- Persistent left sidebar with grouped navigation.
- Wide top content area with strong page heading and compact time filters.
- Screen body built from repeating panels:
  - KPI cards with micro charts
  - section cards with list/table content
  - detail cards with metadata, traces, and timelines
- Collections are mostly list + metrics.
- Details are metadata-first, with code or timeline blocks below.
- Density target is intentionally compact:
  - sidebar rows use narrow vertical rhythm
  - top filters feel like segmented terminal controls, not pill buttons
  - cards and tables use small radii and low-shadow surfaces
  - panels should read as flat monitoring blocks rather than floating dashboard widgets

## Typography

- Use a mono voice across the app for coherence with the terminal theme.
- Large headings remain bold and simple.
- Labels rely on uppercase + tracking instead of heavy ornamentation.
- The type hierarchy should stay restrained; the sample feels compact because large titles are balanced by tight spacing around them.

## Interaction Model

- Low-friction navigation through sidebar and row-level links.
- Repeated views should share one generic collection pattern and one generic detail pattern.
- Even before real APIs, frontend state should mimic future backend contracts:
  - list endpoints return summary metrics + rows
  - detail endpoints return hero metrics + info groups + related events

## Implementation Notes

- Vue app should stay data-driven and easy to reason about.
- Mock data should mirror database-backed Overwatch concepts: requests, executions, jobs, exceptions, queries, cache events, users, logs.
- Nightwatch screenshots supply information architecture; sample HTML supplies the tone and component language.
