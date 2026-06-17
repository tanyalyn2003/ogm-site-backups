# DXF export SOP (reps & template makers)

Use this color convention so OGM Stone Quoter, Kitchen Planner, and AI Quick Start all parse the same file.

## Color / layer rules

| Drawing | Color / layer | Meaning |
|---------|---------------|---------|
| Room / rep zone box | **Red** (DXF color 1, or layer `zone` / `room` / `red`) | Closed rectangle around each rep's work area — **not** imported as stone |
| Sink / cooktop cutout | **Green** (DXF color 3, or layer `cutout` / `cutouts` / `green`) | Hole geometry — imported into designer cutouts |
| Stone outlines | Default (black/white/bylayer) | Countertop pieces inside each zone |
| Room label text | Any color (red text OK) | `TEXT` or `MTEXT` inside the zone → room name |

## Steps

1. Draw a **red closed rectangle** around each rep's or room's work area.
2. Place the **room name** as TEXT inside the box.
3. Draw countertops in the default color inside the box.
4. Draw **sink/cooktop openings in green** (closed polyline or circle).
5. Do **not** use red for cutouts or green for zone boxes.

## Import behavior

- **Multiple red zones** → confirm dialog creates one quote room per zone; stone inside each zone only.
- **No red zones** → legacy single-room import (all outlines into the current room).
- **Green cutouts** → holes land in Layout cutouts (quoter + Kitchen Planner); zone boxes are never countertops.

## Dual code paths (maintainers)

DXF color rules are implemented in **two places** — keep them in sync when changing conventions:

| Runtime | File |
|---------|------|
| Browser (Stone quoter, Kitchen Planner) | `quoter-tool-working/ogm-dxf-classify.js` |
| Server (AI Quick Start DXF upload) | `quoter-tool-working/ai-quickstart-config.php` (`ogm_qs_is_zone`, `ogm_qs_is_cutout`) |

After editing `ogm-dxf-classify.js`, bump the `?v=` query string on script tags in `ogm-quoter-internal.html` and `OGM_KitchenPlanner.html`.

## Tips

- Export closed polylines (`LWPOLYLINE`) for outlines when possible.
- Keep one DXF under 3 MB for AI Quick Start uploads.
- Prefer inches (`$INSUNITS` = 1) or millimeters with units set in the DXF header.
