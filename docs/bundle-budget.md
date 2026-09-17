# Bundle budget

The frontend build is measured locally via the size scripts and can be wired into CI. The initial payload is what the browser
has to download, parse and execute before the first screen is interactive, so it is the
number that is budgeted. Everything else loads later, on demand.

## Definitions

- **Initial JS** — the entry script referenced by `dist/index.html` plus every chunk it
  statically imports. Dynamic `import()` boundaries are not followed, so route chunks are
  excluded.
- **Initial CSS** — the stylesheets referenced by `dist/index.html`.
- **Lazy chunks** — every other emitted chunk. They are reported for visibility but are not
  budgeted.

Sizes are always reported as **gzip** because that is what crosses the wire.

## Budget

| Metric | Budget |
| --- | --- |
| Initial JS | `180 KB` gzip |
| Initial CSS | reported only |

`BUNDLE_BUDGET_KB` overrides the JS budget, for example `BUNDLE_BUDGET_KB=160 npm run size`.

## Commands

| Command | Behaviour |
| --- | --- |
| `npm run size` | Prints the report and exits non-zero when the initial JS is over budget. |
| `npm run size:report` | Prints the report and always exits zero. |
| `npm run build:analyze` | Builds, then prints the report. |

`npm run size` also writes `dist/bundle-report.json` with the per-chunk numbers so the
report can be diffed between builds.

## Current numbers

Measured on the commit that introduced the budget:

```
Initial JS    137.41 KB gzip  (3 files)
Initial CSS    10.63 KB gzip  (1 file)
Initial total 148.04 KB gzip
Lazy chunks   269.99 KB gzip  (104 files)
```

Before the audit the initial JS was `330.55 KB` gzip in a single `index` chunk that bundled
every route, plus a shared `icons` chunk.

## How the budget is met

- **Route-level code splitting.** Every route in `src/App.tsx` is loaded through
  `React.lazy` behind a `Suspense` boundary, so a visit to one page no longer pays for all
  of them. The shell (React, router, query client, layout) stays in the initial payload.
- **Heavy dependencies stay behind a route.** `@xterm/xterm` is only reachable from the
  WebShell route, so its `85 KB` gzip lands in a lazy chunk instead of the initial payload.
- **Icons are tree-shaken per route.** The `icons` manual chunk was removed; `lucide-react`
  icons are now emitted next to the route that imports them.
- **Dead dependencies are gone.** `recharts`, `date-fns`, `clsx` and
  `class-variance-authority` had no imports in `src/` and were removed. Charts are served by
  the in-house `Sparkline` component, which is a few hundred bytes of SVG instead of a
  charting library.

## Known follow-up

Both locale bundles (`src/i18n/locales/en.ts` and `zh.ts`) are eagerly imported by
`src/i18n/index.ts` and together account for roughly **49 KB gzip** of the initial payload,
measured by building with the locale map stubbed out. Only one of them is ever rendered at a
time. Loading the non-active locale through a dynamic import would bring the initial JS down
to roughly `113 KB` gzip, but it makes `useI18n` asynchronous and can flash untranslated
strings on first paint, so it is tracked separately rather than bundled into this change.
