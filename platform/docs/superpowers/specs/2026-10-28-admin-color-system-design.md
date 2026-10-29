# Administrative Color System Design

## Intent

Fix a stable, recognizable color system for the Progressive Activities administration. The administration must feel related to the public brand while remaining calm, predictable, accessible, and independent from each organization's custom public-site colors.

## Approved palette

- Navigation navy: `#173654`
- Navigation navy deep: `#0d253a`
- Brand green: `#205e4d`
- Brand green deep: `#173f35`
- Brand green action: `#176a54`
- Active/action orange: `#ee8436`
- Positive highlight lime: `#b9e986`
- Warm canvas: `#f6f3ed`
- Cool workspace canvas: `#f3f6f4`
- Card surface: `#ffffff`
- Primary border: `#dce6e1`
- Primary muted text: `#667a73`

Semantic status colors remain controlled system colors: green for success, amber for warning/pending, and red for errors/expired states. Organization branding is reserved for public profiles, site previews, documents, and integrations; it must not recolor the administrative shell.

## Approaches considered

1. **Central administrative tokens — selected.** Define named CSS custom properties on the dashboard shell and consume them in the shell and dashboard content. This keeps the palette explicit, reusable, and locally scoped.
2. Keep literal colors in each CSS module. Rejected because small variations would continue to accumulate.
3. Recolor the administration from each organization's brand. Rejected because navigation, contrast, and status meaning would become inconsistent.

## Constraints

- Preserve the approved visual appearance shown on the dashboard.
- Orange is reserved for active navigation and important primary emphasis; it is not a general decorative color.
- Lime is a supporting positive highlight, not a body-text color on light surfaces.
- Existing responsive navigation behavior and semantic status distinctions must not change.
- All text and controls must retain accessible contrast.

