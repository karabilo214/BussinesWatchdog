# ADR 0015: Corporate Colour Palette (Frozen)

Date: 2026-10-09

Status: accepted — owner asked for Tailwind, a corporate palette, and that it stays unchanged.

## Decision

- The frontend uses Tailwind CSS (v4, CSS-first `@theme` tokens).
- Seven 11-step scales generated in OKLCH so steps are perceptually even: `brand` (corporate blue, hue 258), `ink` (cool neutral), `success`, `warning`, `danger`, `info`, and a separate `unknown` (grey-violet) for missing data, unconnected sources and inconclusive results — "unknown" must never look like "fine".
- Every text/background pairing used by the semantic tokens passes WCAG 2.1 AA (computed, see `docs/design/palette.md`).
- Source of truth: `apps/frontend/design/palette.json` (HEX + OKLCH). `apps/frontend/src/styles/palette.css` is generated from it, disables Tailwind's default colours and defines semantic tokens (`primary`, `text-muted`, `ok`, `warn`, `crit`, `note`, `unknown`, …) that components use instead of raw scale steps.
- **Frozen.** `apps/frontend/scripts/check-palette.mjs` (`make frontend-palette-check`) fails when the JSON fingerprint (`807832ec28ec…`) changes, when the CSS drifts from the JSON, or when Tailwind's default colours are re-enabled. Changing the palette requires an owner decision, a new ADR and a deliberate update of the fingerprint.
- A future dark theme may only remap the semantic tokens to other steps of the same scales; no new colours.
