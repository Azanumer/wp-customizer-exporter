# WP Customizer Exporter

Export your theme's Customizer settings as a JSON file and import them into another site (or restore them after a theme switch) — with a dry-run preview before anything changes.

## What is exported

- Every **theme mod** for the active theme (colours, logo, header image, layout options, widget areas — anything the Customizer stores via `set_theme_mod()`).
- The **Customizer CSS** (the "Additional CSS" panel), re-attached to the current theme's custom-CSS post on import.

## How to use

**Tools → Customizer Exporter**

1. **Export:** click *Download Customizer Export (.json)*. The file is named `customizer-<theme>-YYYY-MM-DD.json`.
2. **Import:** upload the JSON file and click *Preview Import*. You get a table of every setting with its status: `added`, `changed`, or `unchanged`.
3. Click **Confirm Import** to apply it. Nothing is written until you confirm, and the preview expires after 10 minutes.

If the export came from a different theme, you get a clear warning — settings the current theme doesn't use are stored but have no visible effect.

## Safety

- Import is two-step (preview → confirm); no silent overwrites.
- Only scalar/array theme-mod values are imported; anything else is skipped.
- Exported CSS is tag-stripped before saving.
- Requires the `manage_options` capability.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
