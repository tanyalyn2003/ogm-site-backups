/**
 * OGM flat-glass price catalog (per sheet / list prices).
 * Used by OGM_GlassQuoter.html for lookups and auto line pricing.
 */
(function (global) {
  'use strict';

  const OGM_GLASS_PRICES = {
    float: {
      clean: { unit: 'sqft', byThickness: { '1/16"': 7.66, '1/8"': 8.06, '1/4"': 9.79 } },
      seam: { unit: 'sqft', byThickness: { '1/8"': 19.58, '1/4"': 10.76, '3/8"': 20.77 } },
      polish: { unit: 'sqft', byThickness: { '1/4"': 11.33, '3/8"': 24.2 } },
      circles: { unit: 'perInch', byThickness: { '1/8"': 2.84, '1/4"': 3.63, '3/8"': 7.87 } },
      tempered: { unit: 'sqft', byThickness: { '1/8"': 12.52, '1/4"': 14.84 } },
    },
    mirror: {
      clean: { unit: 'sqft', byThickness: { '1/8"': 8.58, '1/4"': 10.56 } },
      seam: { unit: 'sqft', byThickness: { '1/8"': 9.24, '1/4"': 20.35 } },
      polish: { unit: 'sqft', byThickness: { '1/4"': 12.54 } },
      circles: { unit: 'perInch', flat: 5.28 },
    },
    igu: {
      clearAnnealed: 12.83,
      clearTempered: 22.66,
      colorAnnealed: 13.73,
      colorTempered: 25.16,
    },
    plexiCut: { unit: 'sqft', byThickness: { '1/16"': 6.48, '1/8"': 9.23, '1/4"': 12.67 } },
    plexiSheet: { unit: 'each', byThickness: { '1/8"': 184.82, '1/4"': 223.44 } },
    starboard: { unit: 'sqft', byThickness: { '1/4"': 13.75, '3/8"': 23.28 } },
    laminatedClear: { unit: 'sqft', byThickness: { '1/4"': 19.71 } },
    wireGlass: { unit: 'sqft', flat: 28.44 },
    jMoldLf: { unit: 'lf', flat: 3.23 },
    jMoldEa: { unit: 'each', flat: 4.72 },
    sweepPerInch: { unit: 'perInch', flat: 0.77 },
    sweepL98: { unit: 'each', flat: 17.12 },
    sweepH98: { unit: 'each', flat: 25.09 },
    bulbSeal95: { unit: 'each', flat: 36.83 },
  };

  const FINISH_TO_EDGE = {
    'Clean Cut': 'clean',
    'Seamed Edge': 'seam',
    'Polished Edge': 'polish',
    'Circle Cut': 'circles',
  };

  /** Glass-type options shown in the quoter (value = catalog key). */
  const GLASS_CATALOG_TYPES = [
    '',
    'Float Glass',
    'Float Glass — Tempered',
    'Mirror Glass',
    'IGU — Clear (Annealed)',
    'IGU — Clear (Tempered)',
    'IGU — Color (Annealed)',
    'IGU — Color (Tempered)',
    'Plexiglass — Cut Size',
    'Plexiglass — Sheet 48×96',
    'White Starboard',
    '1/4" Laminated Clear',
    'Wire Glass',
    'Silver J-Mold (per LF)',
    'Silver J-Mold (each)',
    '3/8" Sweep & Seal (per inch)',
    '3/8" Sweep & Seal — L to 98"',
    '3/8" Sweep & Seal — H to 98" (or over 1/2")',
    'A Bulb Seal 95"',
    'N/A',
  ];

  function num(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : 0;
  }

  function sqftFromInches(wIn, hIn) {
    const w = num(wIn);
    const h = num(hIn);
    if (w <= 0 || h <= 0) return 0;
    return (w * h) / 144;
  }

  function resolveRule(glassType, finish) {
    const gt = String(glassType || '').trim();
    const edge = FINISH_TO_EDGE[String(finish || '').trim()] || '';

    if (gt === 'Float Glass — Tempered' || gt === 'Tempered Glass') {
      return { bucket: OGM_GLASS_PRICES.float.tempered, edgeLabel: 'tempered' };
    }
    if (gt === 'Float Glass — Clear') {
      return { bucket: OGM_GLASS_PRICES.float.clean, edgeLabel: 'clean' };
    }
    if (gt === 'Insulated Glass Unit (IGU) — Annealed') return { flat: OGM_GLASS_PRICES.igu.clearAnnealed, unit: 'sqft' };
    if (gt === 'Insulated Glass Unit (IGU) — Tempered') return { flat: OGM_GLASS_PRICES.igu.clearTempered, unit: 'sqft' };
    if (gt === 'Plexiglass (Acrylic) — Clear') return { bucket: OGM_GLASS_PRICES.plexiCut, edgeLabel: 'cut' };
    if (gt === 'Float Glass' && edge) {
      return { bucket: OGM_GLASS_PRICES.float[edge], edgeLabel: edge };
    }
    if ((gt === 'Mirror Glass — Standard' || gt === 'Mirror Glass — Antique') && edge) {
      return { bucket: OGM_GLASS_PRICES.mirror[edge], edgeLabel: edge };
    }
    if (gt === 'Mirror Glass' && edge) {
      return { bucket: OGM_GLASS_PRICES.mirror[edge], edgeLabel: edge };
    }
    if (gt === 'IGU — Clear (Annealed)') return { flat: OGM_GLASS_PRICES.igu.clearAnnealed, unit: 'sqft' };
    if (gt === 'IGU — Clear (Tempered)') return { flat: OGM_GLASS_PRICES.igu.clearTempered, unit: 'sqft' };
    if (gt === 'IGU — Color (Annealed)') return { flat: OGM_GLASS_PRICES.igu.colorAnnealed, unit: 'sqft' };
    if (gt === 'IGU — Color (Tempered)') return { flat: OGM_GLASS_PRICES.igu.colorTempered, unit: 'sqft' };
    if (gt === 'Plexiglass — Cut Size') return { bucket: OGM_GLASS_PRICES.plexiCut, edgeLabel: 'cut' };
    if (gt === 'Plexiglass — Sheet 48×96') return { bucket: OGM_GLASS_PRICES.plexiSheet, edgeLabel: 'sheet' };
    if (gt === 'White Starboard') return { bucket: OGM_GLASS_PRICES.starboard, edgeLabel: 'starboard' };
    if (gt === '1/4" Laminated Clear') return { bucket: OGM_GLASS_PRICES.laminatedClear, edgeLabel: 'lami' };
    if (gt === 'Wire Glass') return { bucket: OGM_GLASS_PRICES.wireGlass, edgeLabel: 'wire' };
    if (gt === 'Silver J-Mold (per LF)') return { bucket: OGM_GLASS_PRICES.jMoldLf, edgeLabel: 'jMoldLf' };
    if (gt === 'Silver J-Mold (each)') return { bucket: OGM_GLASS_PRICES.jMoldEa, edgeLabel: 'jMoldEa' };
    if (gt === '3/8" Sweep & Seal (per inch)') return { bucket: OGM_GLASS_PRICES.sweepPerInch, edgeLabel: 'sweepIn' };
    if (gt === '3/8" Sweep & Seal — L to 98"') return { bucket: OGM_GLASS_PRICES.sweepL98, edgeLabel: 'sweepL' };
    if (gt === '3/8" Sweep & Seal — H to 98" (or over 1/2")') return { bucket: OGM_GLASS_PRICES.sweepH98, edgeLabel: 'sweepH' };
    if (gt === 'A Bulb Seal 95"') return { bucket: OGM_GLASS_PRICES.bulbSeal95, edgeLabel: 'bulb' };
    return null;
  }

  function unitPriceFromBucket(bucket, thickness) {
    if (!bucket) return null;
    if (bucket.flat != null) return { unit: bucket.unit || 'each', price: bucket.flat };
    const t = String(thickness || '').trim();
    const p = bucket.byThickness && t ? bucket.byThickness[t] : null;
    if (p == null) return null;
    return { unit: bucket.unit, price: p };
  }

  /**
   * @returns {{ amount: number, hint: string } | null}
   */
  function calculateGlassLinePrice(opts) {
    const glassType = opts.glassType;
    const thickness = opts.thickness;
    const finish = opts.finish;
    const wIn = opts.widthIn;
    const hIn = opts.heightIn;
    const qty = Math.max(1, num(opts.qty) || 1);

    const rule = resolveRule(glassType, finish);
    if (!rule) return null;

    let unitInfo;
    if (rule.flat != null) {
      unitInfo = { unit: rule.unit || 'sqft', price: rule.flat };
    } else {
      unitInfo = unitPriceFromBucket(rule.bucket, thickness);
    }
    if (!unitInfo || unitInfo.price == null) return null;

    const { unit, price } = unitInfo;
    let amount = 0;
    let hint = '$' + price.toFixed(2);

    if (unit === 'sqft') {
      const sf = sqftFromInches(wIn, hIn);
      if (sf <= 0) return { amount: null, hint: hint + '/sf — enter W×H (in)' };
      amount = sf * price * qty;
      hint = hint + '/sf × ' + sf.toFixed(2) + ' sf';
    } else if (unit === 'perInch') {
      const dia = Math.max(num(wIn), num(hIn));
      if (dia <= 0) return { amount: null, hint: hint + '/in — enter diameter (in)' };
      amount = dia * price * qty;
      hint = hint + '/in × ' + dia.toFixed(1) + '"';
    } else if (unit === 'lf') {
      const lenIn = Math.max(num(wIn), num(hIn));
      if (lenIn <= 0) return { amount: null, hint: hint + '/LF — enter length (in)' };
      const lf = lenIn / 12;
      amount = lf * price * qty;
      hint = hint + '/LF × ' + lf.toFixed(2) + ' lf';
    } else if (unit === 'each') {
      amount = price * qty;
      hint = hint + ' × ' + qty;
    } else {
      return null;
    }

    if (qty > 1) hint += ' × qty ' + qty;
    return { amount: Math.round(amount * 100) / 100, hint: hint };
  }

  /** Human-readable price book HTML for the reference panel. */
  function glassPriceBookHtml() {
    const rows = (title, lines) =>
      '<div class="gp-sec"><div class="gp-sec-title">' + title + '</div><table class="gp-table">' +
      lines.map(([a, b]) => '<tr><td>' + a + '</td><td>$' + Number(b).toFixed(2) + '</td></tr>').join('') +
      '</table></div>';

    let html = '';
    html += rows('Float — Clean (per sq ft)', Object.entries(OGM_GLASS_PRICES.float.clean.byThickness));
    html += rows('Float — Seam (per sq ft)', Object.entries(OGM_GLASS_PRICES.float.seam.byThickness));
    html += rows('Float — Polish (per sq ft)', Object.entries(OGM_GLASS_PRICES.float.polish.byThickness));
    html += rows('Float — Circles (per inch)', Object.entries(OGM_GLASS_PRICES.float.circles.byThickness));
    html += rows('Float — Tempered (per sq ft)', Object.entries(OGM_GLASS_PRICES.float.tempered.byThickness));
    html += rows('Mirror — Clean (per sq ft)', Object.entries(OGM_GLASS_PRICES.mirror.clean.byThickness));
    html += rows('Mirror — Seam (per sq ft)', Object.entries(OGM_GLASS_PRICES.mirror.seam.byThickness));
    html += rows('Mirror — Polish &amp; Circles', [['Polish 1/4"', 12.54], ['Circles (per inch)', 5.28]]);
    html += rows('IGU (per sq ft)', [
      ['Clear — Annealed', OGM_GLASS_PRICES.igu.clearAnnealed],
      ['Clear — Tempered', OGM_GLASS_PRICES.igu.clearTempered],
      ['Color — Annealed', OGM_GLASS_PRICES.igu.colorAnnealed],
      ['Color — Tempered', OGM_GLASS_PRICES.igu.colorTempered],
    ]);
    html += rows('Plexiglass — Cut (per sq ft)', Object.entries(OGM_GLASS_PRICES.plexiCut.byThickness));
    html += rows('Plexiglass — Sheet 48×96', Object.entries(OGM_GLASS_PRICES.plexiSheet.byThickness));
    html += rows('White Starboard (per sq ft)', Object.entries(OGM_GLASS_PRICES.starboard.byThickness));
    html += rows('Miscellaneous', [
      ['1/4" Lami. Clear (per sq ft)', 19.71],
      ['Wire Glass (per sq ft)', 28.44],
      ['Silver J-Mold / LF', 3.23],
      ['Silver J-Mold (ea)', 4.72],
      ['3/8" Sweep per inch', 0.77],
      ['3/8" Sweep L to 98"', 17.12],
      ['3/8" Sweep H to 98"', 25.09],
      ['A Bulb Seal 95"', 36.83],
    ]);
    return html;
  }

  global.OGM_GLASS_PRICES = OGM_GLASS_PRICES;
  global.GLASS_CATALOG_TYPES = GLASS_CATALOG_TYPES;
  global.FINISH_TO_EDGE = FINISH_TO_EDGE;
  global.calculateGlassLinePrice = calculateGlassLinePrice;
  global.glassPriceBookHtml = glassPriceBookHtml;
})(typeof window !== 'undefined' ? window : globalThis);
