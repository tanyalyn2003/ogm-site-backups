let THREE = null;
let OrbitControls = null;

async function loadThree(){
  if (THREE && OrbitControls) return {ok:true};
  const sources = [
    {
      three: 'https://esm.sh/three@0.164.1',
      controls: 'https://esm.sh/three@0.164.1/examples/jsm/controls/OrbitControls.js'
    },
    {
      three: 'https://esm.run/three@0.164.1',
      controls: 'https://esm.run/three@0.164.1/examples/jsm/controls/OrbitControls.js'
    }
  ];
  const errors = [];
  for (const source of sources) {
    try {
      const threeMod = await import(source.three);
      const controlsMod = await import(source.controls);
      THREE = threeMod;
      OrbitControls = controlsMod.OrbitControls;
      if (!THREE || !OrbitControls) {
        errors.push('Three.js loaded, but controls were missing.');
        continue;
      }
      return {ok:true};
    } catch (err) {
      errors.push((err && err.message) ? err.message : String(err || 'unknown error'));
    }
  }
  return {ok:false, error:`Could not load 3D engine. ${errors.join(' | ')}`.trim()};
}

const LOCAL_PREVIEW_KEY = String(window.__OGM_VIEWER_LOCAL_KEY__ || '');
let SNAPSHOT = window.__OGM_VIEWER_SNAPSHOT__ || null;
const VIEWER_PARAMS = new URLSearchParams(window.location.search || '');
const VIEWER_EMBEDDED = VIEWER_PARAMS.get('embedded') === '1';
const VIEWER_INITIAL_VIEW = String(VIEWER_PARAMS.get('view') || '').toLowerCase();
const VIEWER_ROOM_KEY = String(VIEWER_PARAMS.get('roomKey') || VIEWER_PARAMS.get('room') || '').trim();
const INITIAL_LIGHT_THEME = String(VIEWER_PARAMS.get('theme') || '').toLowerCase() === 'light';
let viewerLightTheme = INITIAL_LIGHT_THEME;
let viewer2DDimensionsOn = true;
let viewerSeparateRooms = false;
const viewer2DHiddenDimensionKeys = new Set();
let viewer2DDimensionHitboxes = [];
if (!SNAPSHOT && LOCAL_PREVIEW_KEY) {
  try {
    const raw = localStorage.getItem(LOCAL_PREVIEW_KEY);
    const payload = raw ? JSON.parse(raw) : null;
    const candidate = payload && typeof payload === 'object' ? (payload.snapshot || payload) : null;
    if (candidate && typeof candidate === 'object') SNAPSHOT = candidate;
  } catch (err) {
    console.error('[OGM viewer local preview]', err);
  }
}

window.ogmSlabImages = window.ogmSlabImages || {};
window.ogmSheetSlabImages = window.ogmSheetSlabImages || {};
if (SNAPSHOT && Array.isArray(SNAPSHOT.stones)) {
  SNAPSHOT.stones.forEach((stone) => {
    if (!stone) return;
    if (stone.imageUrl) {
      window.ogmSlabImages[String(stone.key)] = {
        imageUrl: stone.imageUrl,
        thumbnailUrl: stone.thumbnailUrl || '',
        slabId: stone.slabId || '',
        slabName: stone.slabName || stone.name || ''
      };
    }
    const sheetImages = Array.isArray(stone.sheetImages) ? stone.sheetImages : [];
    sheetImages.forEach((sheet) => {
      if (!sheet || !sheet.imageUrl) return;
      window.ogmSheetSlabImages[`${String(stone.key)}::${Math.max(0, parseInt(sheet.slabIndex, 10) || 0)}`] = {
        imageUrl: sheet.imageUrl,
        thumbnailUrl: sheet.thumbnailUrl || '',
        slabId: sheet.slabId || '',
        slabName: sheet.slabName || stone.name || '',
        sl: Number(sheet.sl) || null,
        sw: Number(sheet.sw) || null
      };
    });
  });
}

window.addEventListener('error', (event) => {
  const msg = event && event.message ? event.message : 'Viewer script error.';
  show3DMessage(`3D viewer error: ${msg}`);
});

window.addEventListener('unhandledrejection', (event) => {
  const reason = event && event.reason;
  const msg = reason && reason.message ? reason.message : String(reason || 'Viewer promise error.');
  show3DMessage(`3D viewer error: ${msg}`);
});

function $(sel){return document.querySelector(sel);}
function clamp(v, a, b){return Math.max(a, Math.min(b, v));}
function slabSheetImageKey(stoneKey, slabIdx){
  return `${String(stoneKey)}::${Math.max(0, parseInt(slabIdx, 10) || 0)}`;
}
function linkedSlabForSheet(stoneKey, slabIdx){
  const sheet = window.ogmSheetSlabImages ? window.ogmSheetSlabImages[slabSheetImageKey(stoneKey, slabIdx)] : null;
  if (sheet && sheet.imageUrl) return sheet;
  const stone = window.ogmSlabImages ? window.ogmSlabImages[String(stoneKey)] : null;
  return stone && stone.imageUrl ? stone : null;
}
function viewerSheetSize(stone, stoneKey, slabIdx){
  const sheet = window.ogmSheetSlabImages ? window.ogmSheetSlabImages[slabSheetImageKey(stoneKey, slabIdx)] : null;
  const slabW = Number(sheet && sheet.sl) || Number(stone && (stone.imageSl || stone.sl)) || 126;
  const slabH = Number(sheet && sheet.sw) || Number(stone && (stone.imageSw || stone.sw)) || 63;
  return {slabW, slabH};
}
function normDeg(d){
  let v = Number(d) || 0;
  v = v % 360;
  if (v < 0) v += 360;
  return v;
}

/** Assembly iframe uses CSS `rotate(deg)` (positive = clockwise). Default Three plan spin is the opposite; negate so 90° in assembly matches 90° in 3D. */
function assemblyCssRotToPlanRad(deg){
  return (-normDeg(deg) * Math.PI) / 180;
}
function rotatedBBox(w, h, deg){
  const theta = (normDeg(deg) * Math.PI) / 180;
  const c = Math.abs(Math.cos(theta));
  const s = Math.abs(Math.sin(theta));
  return {bw: w * c + h * s, bh: w * s + h * c};
}
function parseThicknessInches(tk){
  const s = String(tk || '').trim().toLowerCase();
  const m = s.match(/([0-9]+(?:\.[0-9]+)?)\s*(mm|cm|in)?/);
  if (!m) return 1.18;
  const n = parseFloat(m[1]);
  const u = m[2] || 'cm';
  if (!Number.isFinite(n) || n <= 0) return 1.18;
  if (u === 'mm') return n / 25.4;
  if (u === 'in') return n;
  return n / 2.54; // cm
}

function roundRectPath2D(ctx, x, y, w, h, r){
  const rr = clamp(r, 0, Math.min(w, h) / 2);
  ctx.beginPath();
  ctx.moveTo(x + rr, y);
  ctx.lineTo(x + w - rr, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + rr);
  ctx.lineTo(x + w, y + h - rr);
  ctx.quadraticCurveTo(x + w, y + h, x + w - rr, y + h);
  ctx.lineTo(x + rr, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - rr);
  ctx.lineTo(x, y + rr);
  ctx.quadraticCurveTo(x, y, x + rr, y);
  ctx.closePath();
}

function rotatedRoundRectPath2D(ctx, c, scale){
  if (!c || c.kind !== 'roundRect') return false;
  const wIn = Number(c.w) || 0;
  const hIn = Number(c.h) || 0;
  if (wIn <= 0 || hIn <= 0) return false;
  const rIn = clamp(Number(c.r) || 0, 0, Math.min(wIn, hIn) / 2);
  ctx.save();
  ctx.translate((Number(c.cx) || 0) * scale, (Number(c.cy) || 0) * scale);
  ctx.rotate((normDeg(c.rotation || 0) * Math.PI) / 180);
  roundRectPath2D(ctx, -wIn * scale / 2, -hIn * scale / 2, wIn * scale, hIn * scale, rIn * scale);
  ctx.restore();
  return true;
}

function geomToOuterPath(g){
  const kind = g && g.kind;
  const w = g && Number.isFinite(g.w) ? g.w : 0;
  const h = g && Number.isFinite(g.h) ? g.h : 0;
  if (kind === 'rect') {
    const shape = new THREE.Shape();
    shape.moveTo(-w / 2, -h / 2);
    shape.lineTo(w / 2, -h / 2);
    shape.lineTo(w / 2, h / 2);
    shape.lineTo(-w / 2, h / 2);
    shape.closePath();
    return {shape, w, h};
  }
  if (kind === 'oval') {
    const shape = new THREE.Shape();
    shape.absellipse(0, 0, w / 2, h / 2, 0, Math.PI * 2, false, 0);
    return {shape, w, h};
  }
  if (kind === 'poly') {
    const pts = Array.isArray(g.pts) ? g.pts : [];
    const shape = new THREE.Shape();
    if (pts.length) {
      shape.moveTo(pts[0][0] - w / 2, pts[0][1] - h / 2);
      for (let i = 1; i < pts.length; i++) shape.lineTo(pts[i][0] - w / 2, pts[i][1] - h / 2);
      shape.closePath();
    }
    return {shape, w, h};
  }
  return {shape: new THREE.Shape(), w: 0, h: 0};
}

function applySlabUVs(geometry, opts){
  if (!geometry || !THREE || !geometry.attributes || !geometry.attributes.position) return;
  const slabW = Math.max(1, Number(opts?.slabW) || 1);
  const slabH = Math.max(1, Number(opts?.slabH) || 1);
  const centerX = Number(opts?.centerX) || 0;
  const centerY = Number(opts?.centerY) || 0;
  const rot = normDeg(Number(opts?.rot) || 0);
  const theta = (rot * Math.PI) / 180;
  const c = Math.cos(theta);
  const s = Math.sin(theta);
  const clampUv = opts?.clampUv !== false;
  const pos = geometry.attributes.position;
  const uv = new Float32Array(pos.count * 2);

  for (let i = 0; i < pos.count; i++) {
    const x = pos.getX(i);
    const y = pos.getY(i);
    const rx = x * c - y * s;
    const ry = x * s + y * c;
    const slabX = centerX + rx;
    // placement center is in slab/layout space (Y increases downward like canvas); shape verts are math Y-up — negate ry.
    const slabY = centerY - ry;
    const uRaw = slabX / slabW;
    const vRaw = 1 - (slabY / slabH);
    uv[(i * 2)] = clampUv ? clamp(uRaw, 0, 1) : uRaw;
    uv[(i * 2) + 1] = clampUv ? clamp(vRaw, 0, 1) : vRaw;
  }

  geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
  geometry.attributes.uv.needsUpdate = true;
}

/**
 * Vertical splash uses BoxGeometry(w, h, t) with thin t along local Z — large faces are ±Z.
 * Map slab photo UVs onto those faces only (same mapping as countertop top); thin faces use neutral UV.
 */
function applySlabUVsToBacksplashBox(geometry, opts){
  if (!geometry || !THREE || !geometry.attributes || !geometry.attributes.position) return;
  const slabW = Math.max(1, Number(opts?.slabW) || 1);
  const slabH = Math.max(1, Number(opts?.slabH) || 1);
  const centerX = Number(opts?.centerX) || 0;
  const centerY = Number(opts?.centerY) || 0;
  const rot = normDeg(Number(opts?.rot) || 0);
  const theta = (rot * Math.PI) / 180;
  const c = Math.cos(theta);
  const s = Math.sin(theta);
  const clampUv = opts?.clampUv !== false;
  const halfT = Math.max(1e-6, Number(opts?.halfThickness) || 0);
  const tol = Math.max(1e-3, halfT * 1e-5);
  const pos = geometry.attributes.position;
  const uvArr = new Float32Array(pos.count * 2);
  for (let i = 0; i < pos.count; i++) {
    const x = pos.getX(i);
    const y = pos.getY(i);
    const z = pos.getZ(i);
    if (Math.abs(Math.abs(z) - halfT) <= tol) {
      const rx = x * c - y * s;
      const ry = x * s + y * c;
      const slabX = centerX + rx;
      const slabY = centerY - ry;
      const uRaw = slabX / slabW;
      const vRaw = 1 - (slabY / slabH);
      uvArr[i * 2] = clampUv ? clamp(uRaw, 0, 1) : uRaw;
      uvArr[i * 2 + 1] = clampUv ? clamp(vRaw, 0, 1) : vRaw;
    } else {
      uvArr[i * 2] = 0.5;
      uvArr[i * 2 + 1] = 0.5;
    }
  }
  geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvArr, 2));
  geometry.attributes.uv.needsUpdate = true;
}

function geomLocalVertices(g){
  if (!g) return [];
  if (g.kind === 'rect' || g.kind === 'oval') {
    const w = Number(g.w) || 0;
    const h = Number(g.h) || 0;
    return [
      {x:-w / 2, y:-h / 2},
      {x:w / 2, y:-h / 2},
      {x:w / 2, y:h / 2},
      {x:-w / 2, y:h / 2},
    ];
  }
  if (g.kind === 'poly') {
    return (Array.isArray(g.pts) ? g.pts : []).map((p) => ({
      x:(Number(p[0]) || 0) - ((Number(g.w) || 0) / 2),
      y:(Number(p[1]) || 0) - ((Number(g.h) || 0) / 2)
    }));
  }
  return [];
}

function geomEdgeSegments(g){
  const pts = geomLocalVertices(g);
  if (pts.length < 2) return [];
  const edges = [];
  for (let i = 0; i < pts.length; i++) {
    const a = pts[i];
    const b = pts[(i + 1) % pts.length];
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    edges.push({
      index: i,
      a,
      b,
      dx,
      dy,
      len: Math.hypot(dx, dy),
      mid: {x:(a.x + b.x) / 2, y:(a.y + b.y) / 2}
    });
  }
  return edges;
}

function pointInGeomLocal(geom, x, y){
  if (!geom) return false;
  if (geom.kind === 'rect') {
    return Math.abs(x) <= (Number(geom.w) || 0) / 2 && Math.abs(y) <= (Number(geom.h) || 0) / 2;
  }
  if (geom.kind === 'oval') {
    const rx = (Number(geom.w) || 0) / 2;
    const ry = (Number(geom.h) || 0) / 2;
    if (rx <= 0 || ry <= 0) return false;
    const nx = x / rx;
    const ny = y / ry;
    return (nx * nx) + (ny * ny) <= 1;
  }
  if (geom.kind === 'poly') {
    const pts = geomLocalVertices(geom);
    if (pts.length < 3) return false;
    let inside = false;
    for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
      const xi = pts[i].x;
      const yi = pts[i].y;
      const xj = pts[j].x;
      const yj = pts[j].y;
      const intersect = ((yi > y) !== (yj > y)) && (x < ((xj - xi) * (y - yi)) / ((yj - yi) || 1e-9) + xi);
      if (intersect) inside = !inside;
    }
    return inside;
  }
  return false;
}

function localEdgeOutwardNormal(geom, edge){
  if (!geom || !edge || !Number.isFinite(edge.len) || edge.len <= 0) return {x: 0, y: -1};
  const nx = (-edge.dy) / edge.len;
  const ny = edge.dx / edge.len;
  const probe = 0.75;
  const plusInside = pointInGeomLocal(geom, edge.mid.x + nx * probe, edge.mid.y + ny * probe);
  const minusInside = pointInGeomLocal(geom, edge.mid.x - nx * probe, edge.mid.y - ny * probe);
  if (!plusInside && minusInside) return {x: nx, y: ny};
  if (plusInside && !minusInside) return {x: -nx, y: -ny};
  return {x: nx, y: ny};
}

/** Unit normal from edge toward countertop interior (same convention as ogm-quoter-internal / connector). */
function localEdgeInteriorNormal(geom, edge){
  const o = localEdgeOutwardNormal(geom, edge);
  return {x: -o.x, y: -o.y};
}

function rotatePoint2D(x, y, deg){
  const r = (normDeg(deg) * Math.PI) / 180;
  const c = Math.cos(r);
  const s = Math.sin(r);
  return {x: x * c - y * s, y: x * s + y * c};
}

function assemblyWorldPoint(placement, roomOffset, x, y){
  const r = rotatePoint2D(x, y, placement?.rot || 0);
  return {
    x: (Number(roomOffset?.x) || 0) + (Number(placement?.x) || 0) + r.x,
    y: (Number(roomOffset?.y) || 0) + (Number(placement?.y) || 0) + r.y
  };
}

function layoutPlacementCenter(part){
  const placement = part && part.placement ? part.placement : {};
  const w = Number(part && part.geom && part.geom.w) || 0;
  const h = Number(part && part.geom && part.geom.h) || 0;
  const rot = normDeg(placement.rot || 0);
  const bb = rotatedBBox(w, h, rot);
  return {
    x: (Number(placement.x) || 0) + bb.bw / 2,
    y: (Number(placement.y) || 0) + bb.bh / 2
  };
}

function assemblyPlacementBounds(part){
  const placement = part && part.assemblyPlacement ? part.assemblyPlacement : null;
  if (!placement || !part || !part.geom) return null;
  const verts = geomLocalVertices(part.geom);
  if (!verts.length) return null;
  const pts = verts.map((v) => assemblyWorldPoint(placement, {x:0, y:0}, v.x, v.y));
  const xs = pts.map((p) => p.x);
  const ys = pts.map((p) => p.y);
  return {
    minX: Math.min(...xs),
    minY: Math.min(...ys),
    maxX: Math.max(...xs),
    maxY: Math.max(...ys)
  };
}

function getAssemblyWorldEdge(part, edgeIndex, roomOffset){
  const placement = part && part.assemblyPlacement;
  if (!part || !placement) return null;
  const edge = geomEdgeSegments(part.geom).find((e) => e.index === edgeIndex);
  if (!edge) return null;
  const start = assemblyWorldPoint(placement, roomOffset, edge.a.x, edge.a.y);
  const end = assemblyWorldPoint(placement, roomOffset, edge.b.x, edge.b.y);
  return {
    start,
    end,
    len: edge.len,
    angle: Math.atan2(end.y - start.y, end.x - start.x)
  };
}

function computeAssemblyRoomOffsets(parts, opts){
  opts = opts || {};
  const collapseToSingleOrigin = !!opts.collapseToSingleOrigin;
  const roomMap = new Map();
  (parts || []).forEach((part) => {
    const roomId = String(part.assemblyPlacement?.roomId ?? part.roomId ?? 'room');
    if (!roomMap.has(roomId)) roomMap.set(roomId, []);
    roomMap.get(roomId).push(part);
  });
  // When Assembly lists edge connections, 3D should show one physical run — not side-by-side "rooms".
  if (collapseToSingleOrigin && parts && parts.length) {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    parts.forEach((part) => {
      const b = assemblyPlacementBounds(part);
      if (!b) return;
      minX = Math.min(minX, b.minX);
      minY = Math.min(minY, b.minY);
      maxX = Math.max(maxX, b.maxX);
      maxY = Math.max(maxY, b.maxY);
    });
    if (!Number.isFinite(minX)) {
      minX = 0; minY = 0; maxX = 120; maxY = 60;
    }
    const width = Math.max(1, maxX - minX);
    const height = Math.max(1, maxY - minY);
    const off = {x: -minX, y: -minY, width, height};
    const offsets = new Map();
    roomMap.forEach((_roomParts, roomId) => {
      offsets.set(roomId, off);
    });
    return offsets;
  }
  const offsets = new Map();
  let cursorX = 0;
  const gap = 36;
  roomMap.forEach((roomParts, roomId) => {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    roomParts.forEach((part) => {
      const b = assemblyPlacementBounds(part);
      if (!b) return;
      minX = Math.min(minX, b.minX);
      minY = Math.min(minY, b.minY);
      maxX = Math.max(maxX, b.maxX);
      maxY = Math.max(maxY, b.maxY);
    });
    if (!Number.isFinite(minX)) {
      minX = 0; minY = 0; maxX = 120; maxY = 60;
    }
    const width = Math.max(1, maxX - minX);
    offsets.set(roomId, {x: cursorX - minX, y: -minY, width, height: Math.max(1, maxY - minY)});
    cursorX += width + gap;
  });
  return offsets;
}

function layoutPartIdForAssemblySplash(id){
  return String(id || '').replace('-assembly-splash-', '-backsplash-');
}

/** Snapshot may omit partType (defaults to countertop); ids from quoter are room-{n}-backsplash-{m}. */
function isBacksplashPart(p){
  if (!p) return false;
  if (p.partType === 'backsplash') return true;
  return /^room-\d+-backsplash-\d+$/.test(String(p.id || ''));
}

function isWaterfallPart(p){
  if (!p) return false;
  if (p.partType === 'waterfall') return true;
  return /-waterfall-\d+$/.test(String(p.id || ''));
}

function addCutoutHoles(shape, cutouts){
  (cutouts || []).forEach((c) => {
    if (!c || c.kind !== 'roundRect') return;
    const w = Number(c.w) || 0;
    const h = Number(c.h) || 0;
    if (w <= 0 || h <= 0) return;
    const r = clamp(Number(c.r) || 0, 0, Math.min(w, h) / 2);
    const cx = Number(c.cx) || 0;
    const cy = Number(c.cy) || 0;
    const hole = new THREE.Path();
    const theta = (normDeg(c.rotation || 0) * Math.PI) / 180;
    const rc = Math.cos(theta);
    const rs = Math.sin(theta);
    const pt = (x, y) => {
      const dx = x - cx;
      const dy = y - cy;
      return {x: cx + dx * rc - dy * rs, y: cy + dx * rs + dy * rc};
    };
    const moveTo = (x, y) => {
      const p = pt(x, y);
      hole.moveTo(p.x, p.y);
    };
    const lineTo = (x, y) => {
      const p = pt(x, y);
      hole.lineTo(p.x, p.y);
    };
    const quadTo = (cpx, cpy, x, y) => {
      const cp = pt(cpx, cpy);
      const p = pt(x, y);
      hole.quadraticCurveTo(cp.x, cp.y, p.x, p.y);
    };

    const x0 = cx - w / 2;
    const y0 = cy - h / 2;

    // Rounded rectangle path.
    moveTo(x0 + r, y0);
    lineTo(x0 + w - r, y0);
    quadTo(x0 + w, y0, x0 + w, y0 + r);
    lineTo(x0 + w, y0 + h - r);
    quadTo(x0 + w, y0 + h, x0 + w - r, y0 + h);
    lineTo(x0 + r, y0 + h);
    quadTo(x0, y0 + h, x0, y0 + h - r);
    lineTo(x0, y0 + r);
    quadTo(x0, y0, x0 + r, y0);

    shape.holes.push(hole);
  });
}

function setTheme(isLight){
  viewerLightTheme = !!isLight;
  const root = document.documentElement;
  if (isLight) {
    root.style.setProperty('--bg', '#f8fafc');
    root.style.setProperty('--panel', '#ffffff');
    root.style.setProperty('--txt', '#0f172a');
    root.style.setProperty('--muted', '#475569');
    root.style.setProperty('--line', '#e2e8f0');
    root.style.setProperty('--btn', '#0f172a');
    root.style.setProperty('--btn2', '#ffffff');
  } else {
    root.style.setProperty('--bg', '#0b1222');
    root.style.setProperty('--panel', '#0f172a');
    root.style.setProperty('--txt', '#e5e7eb');
    root.style.setProperty('--muted', '#94a3b8');
    root.style.setProperty('--line', '#1e293b');
    root.style.setProperty('--btn', '#111827');
    root.style.setProperty('--btn2', '#0b1222');
  }
}

function viewer2DPalette(){
  return viewerLightTheme
    ? {
        bg: '#ffffff',
        panel: '#ffffff',
        slabBorder: '#7f1d1d',
        countertop: '#1d4ed8',
        countertopFill: 'rgba(29,78,216,.12)',
        backsplash: '#166534',
        backsplashFill: 'rgba(22,101,52,.12)',
        waterfall: '#0f766e',
        waterfallFill: 'rgba(15,118,110,.12)',
        labelHalo: 'rgba(255,255,255,.9)',
        labelText: '#0f172a',
        roomBadgeBg: 'rgba(255,255,255,.92)',
        roomBadgeBorder: 'rgba(127,29,29,.32)',
        roomBadgeText: '#7f1d1d',
        cutout: '#334155',
        seam: '#7f1d1d',
        dimLine: '#7f1d1d',
        dimLineMuted: '#334155',
        dimBadgeBg: '#ffffff',
        dimBadgeBorder: 'rgba(127,29,29,.38)',
        dimText: '#7f1d1d',
        dimTextMuted: '#1e3a8a'
      }
    : {
        bg: '#0b1222',
        panel: '#0b1222',
        slabBorder: '#c78100',
        countertop: '#93c5fd',
        countertopFill: 'rgba(147,197,253,.22)',
        backsplash: '#a7f3d0',
        backsplashFill: 'rgba(167,243,208,.22)',
        waterfall: '#38bdf8',
        waterfallFill: 'rgba(56,189,248,.22)',
        labelHalo: 'rgba(2,6,23,.82)',
        labelText: '#f8fafc',
        roomBadgeBg: 'rgba(2,6,23,.82)',
        roomBadgeBorder: 'rgba(196,160,90,.45)',
        roomBadgeText: '#fcd34d',
        cutout: '#e2e8f0',
        seam: '#f59e0b',
        dimLine: 'rgba(196,160,90,.82)',
        dimLineMuted: 'rgba(148,163,184,.68)',
        dimBadgeBg: 'rgba(2,6,23,.78)',
        dimBadgeBorder: 'rgba(148,163,184,.34)',
        dimText: '#fcd34d',
        dimTextMuted: '#cbd5e1'
      };
}

function groupSnapshot(snapshot){
  const stonesArr = Array.isArray(snapshot.stones) ? snapshot.stones : [];
  const stonesByKey = new Map(stonesArr.map((s) => [String(s.key), s]));
  const parts = Array.isArray(snapshot.parts) ? snapshot.parts : [];
  const byStone = new Map();
  parts.forEach((p) => {
    const key = String(p.stoneKey);
    if (!byStone.has(key)) byStone.set(key, []);
    byStone.get(key).push(p);
  });
  return {stonesByKey, byStone};
}

/** Match quoter layout when extra empty slabs exist (layoutSlabCount on snapshot stone). */
function effectiveSlabCountForViewer(stone, parts){
  let maxFromParts = 0;
  (parts || []).forEach((p) => {
    maxFromParts = Math.max(maxFromParts, (Number(p.placement?.slabIndex) || 0) + 1);
  });
  const n = Number(stone && stone.layoutSlabCount);
  return Math.max(1, maxFromParts, (Number.isFinite(n) && n >= 1) ? n : 0);
}

function viewerRoomKeyForPart(part){
  const raw = part?.assemblyPlacement?.roomId ?? part?.roomId ?? part?.meta?.roomId ?? part?.meta?.roomName ?? part?.roomName ?? part?.displayLabel ?? part?.label ?? 'Room';
  const key = String(raw || '').trim();
  return key || 'Room';
}

function viewerRoomLabelForPart(part){
  const raw = part?.meta?.displayLabel ?? part?.meta?.roomName ?? part?.roomName ?? part?.displayLabel ?? part?.label ?? part?.assemblyPlacement?.roomId ?? part?.roomId ?? 'Room';
  const label = String(raw || '').trim();
  return label || 'Room';
}

function viewerRoomKeyAliasesForPart(part){
  const vals = [
    part?.assemblyPlacement?.roomId,
    part?.roomId,
    part?.meta?.roomId,
    part?.meta?.roomName,
    part?.roomName,
    part?.displayLabel,
    part?.label
  ];
  const out = [];
  vals.forEach((value) => {
    const key = String(value ?? '').trim();
    if (key && !out.includes(key)) out.push(key);
  });
  return out.length ? out : [viewerRoomKeyForPart(part)];
}

function groupCounterPartsByRoom(parts, separate = viewerSeparateRooms){
  if (!separate) return [{key:'all', label:'All Rooms', parts: parts || []}];
  const groups = [];
  const lookup = new Map();
  (parts || []).forEach((part) => {
    const key = viewerRoomKeyForPart(part);
    if (!lookup.has(key)) {
      const group = {key, label: viewerRoomLabelForPart(part), parts: []};
      lookup.set(key, group);
      groups.push(group);
    }
    lookup.get(key).parts.push(part);
  });
  return groups;
}

function viewerCounterRoomGroups(snapshot){
  const parts = Array.isArray(snapshot && snapshot.parts) ? snapshot.parts : [];
  const groups = [];
  const lookup = new Map();
  parts.forEach((part) => {
    if (!part || part.partType !== 'countertop') return;
    const key = viewerRoomKeyForPart(part);
    if (!lookup.has(key)) {
      const group = {key, label: viewerRoomLabelForPart(part), parts: []};
      lookup.set(key, group);
      groups.push(group);
    }
    lookup.get(key).parts.push(part);
  });
  return groups;
}

function viewerRoomKeyMatches(a, b){
  return String(a || '').trim() === String(b || '').trim();
}

function viewerRoomKeyMatchesPart(part, roomKey){
  const target = String(roomKey || '').trim();
  if (!part || !target) return false;
  return viewerRoomKeyAliasesForPart(part).some((key) => viewerRoomKeyMatches(key, target));
}

function filterViewerSnapshotByRoom(snapshot, roomKey){
  if (!snapshot || !roomKey) return snapshot;
  const parts = Array.isArray(snapshot.parts) ? snapshot.parts : [];
  const allPartsById = new Map(parts.map((p) => [String(p && p.id), p]));
  const hostRoomById = new Map();
  parts.forEach((part) => {
    if (!part || part.partType !== 'countertop') return;
    hostRoomById.set(String(part.id), viewerRoomKeyAliasesForPart(part));
  });

  const keepIds = new Set();
  parts.forEach((part) => {
    if (!part) return;
    const id = String(part.id || '');
    if (!id) return;
    if (viewerRoomKeyMatchesPart(part, roomKey)) {
      keepIds.add(id);
      return;
    }
    if ((isBacksplashPart(part) || isWaterfallPart(part)) && viewerRoomKeyMatchesPart(part, roomKey)) {
      keepIds.add(id);
    }
  });

  const assembly = snapshot && typeof snapshot.assembly === 'object' ? snapshot.assembly : null;
  const splashes = Array.isArray(assembly?.splashes) ? assembly.splashes.filter((s) => {
    const hostId = String(s && s.hostPartId);
    const host = allPartsById.get(hostId);
    const aliases = hostRoomById.get(hostId) || (host ? viewerRoomKeyAliasesForPart(host) : []);
    return aliases.some((key) => viewerRoomKeyMatches(key, roomKey));
  }) : [];
  splashes.forEach((s) => {
    const hostId = String(s && s.hostPartId);
    if (hostId) keepIds.add(hostId);
    const splashPartId = layoutPartIdForAssemblySplash(s && s.id);
    if (splashPartId) keepIds.add(String(splashPartId));
  });

  const waterfalls = Array.isArray(assembly?.waterfalls) ? assembly.waterfalls.filter((w) => {
    const hostId = String(w && w.hostPartId);
    const host = allPartsById.get(hostId);
    const aliases = hostRoomById.get(hostId) || (host ? viewerRoomKeyAliasesForPart(host) : []);
    return aliases.some((key) => viewerRoomKeyMatches(key, roomKey));
  }) : [];
  waterfalls.forEach((w) => {
    const hostId = String(w && w.hostPartId);
    if (hostId) keepIds.add(hostId);
    if (w && w.id) keepIds.add(String(w.id));
  });

  const filteredParts = parts.filter((part) => keepIds.has(String(part && part.id)));
  const filteredAssembly = assembly ? {...assembly} : null;
  if (filteredAssembly) {
    filteredAssembly.splashes = splashes;
    filteredAssembly.waterfalls = waterfalls;
    filteredAssembly.matches = Array.isArray(assembly.matches)
      ? assembly.matches.filter((match) => keepIds.has(String(match && (match.partAId ?? match.a))) && keepIds.has(String(match && (match.partBId ?? match.b))))
      : [];
  }
  return {
    ...snapshot,
    parts: filteredParts,
    assembly: filteredAssembly || snapshot.assembly
  };
}

function viewerActiveSnapshot(){
  return VIEWER_ROOM_KEY ? filterViewerSnapshotByRoom(SNAPSHOT, VIEWER_ROOM_KEY) : SNAPSHOT;
}

function viewerActiveRoomLabel(snapshot){
  if (!VIEWER_ROOM_KEY) return '';
  const snap = snapshot || SNAPSHOT;
  const parts = Array.isArray(snap && snap.parts) ? snap.parts : [];
  const partHit = parts.find((part) => part && part.partType === 'countertop' && viewerRoomKeyMatchesPart(part, VIEWER_ROOM_KEY));
  if (partHit) return viewerRoomLabelForPart(partHit);
  const groups = viewerCounterRoomGroups(snap);
  const hit = groups.find((group) => viewerRoomKeyMatches(group.key, VIEWER_ROOM_KEY));
  return hit ? hit.label : VIEWER_ROOM_KEY;
}

function notifyViewerReady(view){
  if (!VIEWER_EMBEDDED) return;
  try {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({type:'OGM_VIEWER_READY', view, roomKey: VIEWER_ROOM_KEY}, '*');
    }
  } catch (_) {}
}

function render2DLayout(snapshot){
  const host = $('#twoD');
  if (!host) return;
  host.innerHTML = '';
  const {stonesByKey, byStone} = groupSnapshot(snapshot);

  const PAD = 14;
  const maxCanvasW = 720;

  for (const [stoneKey, parts] of byStone.entries()) {
    const stone = stonesByKey.get(String(stoneKey));
    const slabCount = effectiveSlabCountForViewer(stone, parts);

    const stoneDiv = document.createElement('div');
    stoneDiv.className = 'stone';
    stoneDiv.innerHTML = `
      <div class="stoneHead">
        <div>
          <div class="stoneName">${escapeHtml(stone?.name || 'Stone')}</div>
          <div class="stoneMeta">${slabCount} slab${slabCount !== 1 ? 's' : ''}</div>
        </div>
        <div class="stoneMeta">View-only</div>
      </div>
    `;

    for (let i = 0; i < slabCount; i++) {
      const size = viewerSheetSize(stone, stoneKey, i);
      const slabW = size.slabW;
      const slabH = size.slabH;
      const canvasW = maxCanvasW;
      const canvasH = Math.max(240, Math.round(canvasW * (slabH / slabW)));
      const scale = (canvasW - PAD * 2) / slabW;
      const canvas = document.createElement('canvas');
      canvas.width = canvasW;
      canvas.height = canvasH;

      const ctx = canvas.getContext('2d');
      const palette = viewer2DPalette();
      ctx.clearRect(0, 0, canvasW, canvasH);
      ctx.fillStyle = palette.bg;
      ctx.fillRect(0, 0, canvasW, canvasH);
      const slabImgData = linkedSlabForSheet(stoneKey, i);
      const slabImgUrl = slabImgData && slabImgData.imageUrl ? String(slabImgData.imageUrl) : '';
      if (slabImgUrl) {
        window.__sheetTextureCache = window.__sheetTextureCache || {};
        window.__sheetTexturePending = window.__sheetTexturePending || {};
        const cached = window.__sheetTextureCache[slabImgUrl];
        if (cached) {
          ctx.save();
          ctx.globalAlpha = 0.92;
          ctx.drawImage(cached, PAD, PAD, slabW * scale, slabH * scale);
          ctx.restore();
        } else if (!window.__sheetTexturePending[slabImgUrl]) {
          window.__sheetTexturePending[slabImgUrl] = true;
          const img = new Image();
          img.crossOrigin = 'anonymous';
          img.onload = function(){
            window.__sheetTextureCache[slabImgUrl] = img;
            delete window.__sheetTexturePending[slabImgUrl];
            if (typeof render2D === 'function' && typeof SNAPSHOT !== 'undefined' && SNAPSHOT) {
              render2D(viewerActiveSnapshot());
              notifyViewerReady('2d');
            }
          };
          img.onerror = function(){
            delete window.__sheetTexturePending[slabImgUrl];
          };
          img.src = slabImgUrl;
        }
      }
      ctx.strokeStyle = palette.slabBorder;
      ctx.lineWidth = 2;
      ctx.strokeRect(PAD, PAD, slabW * scale, slabH * scale);

      // Draw parts in slab.
      parts
        .filter((p) => (Number(p.placement?.slabIndex) || 0) === i)
        .forEach((p) => drawPart2D(ctx, p, PAD, scale));

      const meta = document.createElement('div');
      meta.className = 'stoneMeta';
      meta.style.margin = '8px 0 8px';
      meta.textContent = `Sheet ${i + 1} · ${slabW}"×${slabH}"`;
      stoneDiv.appendChild(meta);
      stoneDiv.appendChild(canvas);
    }

    host.appendChild(stoneDiv);
  }
}

function render2D(snapshot){
  if (render2DRoomCards(snapshot)) return;
  if (renderAssembled2D(snapshot)) return;
  render2DLayout(snapshot);
}

function render2DRoomCards(snapshot){
  if (!viewerSeparateRooms || VIEWER_ROOM_KEY) return false;
  const host = $('#twoD');
  if (!host) return false;
  const groups = viewerCounterRoomGroups(snapshot);
  if (groups.length <= 1) return false;
  host.innerHTML = '';
  let rendered = false;
  groups.forEach((group) => {
    const roomSnapshot = filterViewerSnapshotByRoom(snapshot, group.key);
    if (renderAssembled2D(roomSnapshot, {host, append: true, forceTogether: true, roomLabel: group.label})) rendered = true;
  });
  return rendered;
}

function renderAssembled2D(snapshot, opts = {}){
  const host = opts.host || $('#twoD');
  if (!host) return false;
  const parts = Array.isArray(snapshot && snapshot.parts) ? snapshot.parts : [];
  const assembly = snapshot && typeof snapshot.assembly === 'object' ? snapshot.assembly : null;
  const counterParts = parts.filter((p) => p && p.partType === 'countertop' && p.geom);
  if (!counterParts.length) return false;

  const allPartsById = new Map(parts.map((p) => [String(p && p.id), p]));
  const {stonesByKey} = groupSnapshot(snapshot);
  const arrangedCounters = new Map();
  const flatItems = [];
  const roomSections = [];
  const COUNTER_GAP = 18;
  const ROW_GAP = 34;
  const MAX_ROW_W = 260;
  const ROOM_GAP = 58;
  const ROOM_TITLE_H = 18;
  let cursorX = 0;
  let cursorY = 0;
  let rowH = 0;

  const separateRoomsInCanvas = !opts.forceTogether && viewerSeparateRooms;
  groupCounterPartsByRoom(counterParts, separateRoomsInCanvas).forEach((group, groupIndex) => {
    if (separateRoomsInCanvas) {
      if (groupIndex > 0) {
        cursorX = 0;
        cursorY += rowH + ROOM_GAP;
        rowH = 0;
      }
      roomSections.push({label: group.label, x: 0, y: cursorY});
      cursorY += ROOM_TITLE_H;
      cursorX = 0;
      rowH = 0;
    }
    (group.parts || []).forEach((part) => {
      const geom = part.geom || {};
      const w = Math.max(1, Number(geom.w) || 1);
      const h = Math.max(1, Number(geom.h) || 1);
      if (cursorX > 0 && cursorX + w > MAX_ROW_W) {
        cursorX = 0;
        cursorY += rowH + ROW_GAP;
        rowH = 0;
      }
      const item = {
        type: 'countertop',
        part,
        geom: part.geom,
        label: part.label || 'Countertop',
        roomKey: viewerRoomKeyForPart(part),
        roomLabel: viewerRoomLabelForPart(part),
        placement: {
          x: cursorX + w / 2,
          y: cursorY + h / 2,
          rot: 0
        },
        cutouts: Array.isArray(part.cutouts) ? part.cutouts : [],
        seams: Array.isArray(part.seams) ? part.seams : []
      };
      flatItems.push(item);
      arrangedCounters.set(String(part.id), item);
      cursorX += w + COUNTER_GAP;
      rowH = Math.max(rowH, h);
    });
  });

  const splashes = Array.isArray(assembly?.splashes) ? assembly.splashes : [];
  splashes.forEach((splash) => {
    const hostPart = allPartsById.get(String(splash.hostPartId));
    const hostItem = arrangedCounters.get(String(splash.hostPartId));
    if (!hostPart || !hostPart.geom || !hostItem) return;
    const edgeIndex = parseInt(splash.edgeIndex, 10) || 0;
    const edge = geomEdgeSegments(hostPart.geom).find((e) => e.index === edgeIndex);
    if (!edge || edge.len <= 0) return;
    const len = clamp(Number(splash.length) || 0, 0, edge.len);
    const height = Math.max(0, Number(splash.height) || 0);
    if (len <= 0 || height <= 0) return;
    const offset = clamp(Number(splash.offset) || 0, 0, Math.max(0, edge.len - len));
    const userOffsetIn = Number(splash.offsetIn) || 0;
    const ux = edge.dx / edge.len;
    const uy = edge.dy / edge.len;
    const midT = offset + len / 2 + userOffsetIn;
    const mid = {x: edge.a.x + ux * midT, y: edge.a.y + uy * midT};
    const outward = localEdgeOutwardNormal(hostPart.geom, edge);
    const displayGap = 8;
    const splashPart = allPartsById.get(layoutPartIdForAssemblySplash(splash.id));
    flatItems.push({
      type: 'backsplash',
      part: splashPart || hostPart,
      geom: {kind:'rect', w: len, h: height},
      label: 'Backsplash',
      roomKey: viewerRoomKeyForPart(hostPart),
      roomLabel: viewerRoomLabelForPart(hostPart),
      placement: {
        x: (Number(hostItem.placement.x) || 0) + mid.x + outward.x * (height / 2 + displayGap),
        y: (Number(hostItem.placement.y) || 0) + mid.y + outward.y * (height / 2 + displayGap),
        rot: Math.atan2(uy, ux) * 180 / Math.PI
      },
      cutouts: []
    });
  });

  const waterfalls = Array.isArray(assembly?.waterfalls) ? assembly.waterfalls : [];
  waterfalls.forEach((waterfall) => {
    const hostPart = allPartsById.get(String(waterfall.hostPartId));
    const hostItem = arrangedCounters.get(String(waterfall.hostPartId));
    if (!hostPart || !hostPart.geom || !hostItem) return;
    const edgeIndex = parseInt(waterfall.edgeIndex, 10) || 0;
    const edge = geomEdgeSegments(hostPart.geom).find((e) => e.index === edgeIndex);
    if (!edge || edge.len <= 0) return;
    const len = clamp(Number(waterfall.length) || 0, 0, edge.len);
    const height = Math.max(0, Number(waterfall.height) || 0);
    if (len <= 0 || height <= 0) return;
    const offset = clamp(Number(waterfall.offset) || 0, 0, Math.max(0, edge.len - len));
    const userOffsetIn = Number(waterfall.offsetIn) || 0;
    const ux = edge.dx / edge.len;
    const uy = edge.dy / edge.len;
    const midT = offset + len / 2 + userOffsetIn;
    const mid = {x: edge.a.x + ux * midT, y: edge.a.y + uy * midT};
    const outward = localEdgeOutwardNormal(hostPart.geom, edge);
    const displayGap = 10;
    const waterfallPart = allPartsById.get(String(waterfall.id));
    flatItems.push({
      type: 'waterfall',
      part: waterfallPart || hostPart,
      geom: {kind:'rect', w: len, h: height},
      label: 'Waterfall',
      roomKey: viewerRoomKeyForPart(hostPart),
      roomLabel: viewerRoomLabelForPart(hostPart),
      placement: {
        x: (Number(hostItem.placement.x) || 0) + mid.x + outward.x * (height / 2 + displayGap),
        y: (Number(hostItem.placement.y) || 0) + mid.y + outward.y * (height / 2 + displayGap),
        rot: Math.atan2(uy, ux) * 180 / Math.PI
      },
      cutouts: []
    });
  });

  if (!flatItems.length) return false;

  const rawBounds = assembled2DBounds(flatItems);
  if (!rawBounds) return false;
  const VIEWER_BOUNDS_PAD_IN = 12;
  const bounds = {
    minX: rawBounds.minX - VIEWER_BOUNDS_PAD_IN,
    minY: rawBounds.minY - VIEWER_BOUNDS_PAD_IN,
    maxX: rawBounds.maxX + VIEWER_BOUNDS_PAD_IN,
    maxY: rawBounds.maxY + VIEWER_BOUNDS_PAD_IN
  };

  if (!opts.append) host.innerHTML = '';
  const panel = document.createElement('div');
  panel.className = 'stone';
  const activeRoomLabel = opts.roomLabel || viewerActiveRoomLabel(snapshot);
  panel.innerHTML = `
    <div class="stoneHead">
      <div>
        <div class="stoneName">${escapeHtml(activeRoomLabel ? `${activeRoomLabel} — 2D View` : 'Assembled 2D View')}</div>
        <div class="stoneMeta">Countertops, sink cutouts, backsplashes, and waterfalls shown flat · click a dimension label to hide it</div>
      </div>
      <div class="stoneMeta">View-only</div>
    </div>
  `;

  const PAD = 24;
  const maxCanvasW = 960;
  const contentW = Math.max(1, bounds.maxX - bounds.minX);
  const contentH = Math.max(1, bounds.maxY - bounds.minY);
  const canvasW = maxCanvasW;
  const canvasH = Math.max(280, Math.round(canvasW * (contentH / contentW)));
  const scale = Math.min((canvasW - PAD * 2) / contentW, (canvasH - PAD * 2) / contentH);
  const canvas = document.createElement('canvas');
  canvas.width = canvasW;
  canvas.height = canvasH;
  const ctx = canvas.getContext('2d');
  const palette = viewer2DPalette();
  ctx.clearRect(0, 0, canvasW, canvasH);
  ctx.fillStyle = palette.bg;
  ctx.fillRect(0, 0, canvasW, canvasH);
  draw2DRoomSectionLabels(ctx, roomSections, {
    scale,
    originX: bounds.minX,
    originY: bounds.minY,
    pad: PAD
  });

  if (!opts.append) viewer2DDimensionHitboxes = [];
  const dimensionState = {occupied: [], hitboxes: viewer2DDimensionHitboxes};
  const sortedItems = flatItems.slice().sort((a, b) => {
    const order = {backsplash: 0, waterfall: 1, countertop: 2};
    return (order[a.type] || 0) - (order[b.type] || 0);
  });
  sortedItems.forEach((item) => drawAssembledItem2D(ctx, item, stonesByKey, {
    scale,
    originX: bounds.minX,
    originY: bounds.minY,
    pad: PAD
  }));
  if (viewer2DDimensionsOn) {
    const dimensionItems = sortedItems.slice().sort((a, b) => {
      const order = {countertop: 0, waterfall: 1, backsplash: 2};
      return (order[a.type] || 0) - (order[b.type] || 0);
    });
    dimensionItems.forEach((item) => drawAssembledDimensions2D(ctx, item, {
      scale,
      originX: bounds.minX,
      originY: bounds.minY,
      pad: PAD,
      dimensionState
    }));
  }

  canvas.title = 'Click a dimension label to hide it. Use Dimensions to show or hide all labels.';
  canvas.addEventListener('click', on2DCanvasClick);
  panel.appendChild(canvas);
  host.appendChild(panel);
  sync2DControls();
  return true;
}

function assembled2DBounds(items){
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
  (items || []).forEach((item) => {
    const geom = item && item.geom;
    const placement = item && item.placement;
    if (!geom || !placement) return;
    geomLocalVertices(geom).forEach((v) => {
      const p = assemblyWorldPoint(placement, {x:0, y:0}, v.x, v.y);
      minX = Math.min(minX, p.x);
      minY = Math.min(minY, p.y);
      maxX = Math.max(maxX, p.x);
      maxY = Math.max(maxY, p.y);
    });
  });
  if (!Number.isFinite(minX)) return null;
  return {minX, minY, maxX, maxY};
}

function drawAssembledItem2D(ctx, item, stonesByKey, opts){
  const part = item.part || {};
  const geom = item.geom;
  const placement = item.placement || {};
  const scale = Number(opts.scale) || 1;
  const pad = Number(opts.pad) || 0;
  const cx = pad + ((Number(placement.x) || 0) - opts.originX) * scale;
  const cy = pad + ((Number(placement.y) || 0) - opts.originY) * scale;
  const rot = normDeg(placement.rot || 0);

  ctx.save();
  ctx.translate(cx, cy);
  ctx.rotate((rot * Math.PI) / 180);

  const stone = stonesByKey.get(String(part.stoneKey));
  const sheetIdx = Number(part.placement?.slabIndex) || 0;
  const sheet = linkedSlabForSheet(part.stoneKey || '', sheetIdx);
  const imgUrl = sheet && sheet.imageUrl ? String(sheet.imageUrl) : '';
  const drewTexture = drawAssembledTextureFill(ctx, geom, part, stone, imgUrl, scale);
  const palette = viewer2DPalette();
  if (!drewTexture) {
    ctx.fillStyle = item.type === 'backsplash' ? palette.backsplashFill : (item.type === 'waterfall' ? palette.waterfallFill : palette.countertopFill);
    const okFill = pathOuter2D(ctx, geom, scale);
    if (okFill) ctx.fill();
  }

  const stroke = item.type === 'backsplash' ? palette.backsplash : (item.type === 'waterfall' ? palette.waterfall : palette.countertop);
  ctx.strokeStyle = stroke;
  ctx.lineWidth = item.type === 'countertop' ? 2 : 1.5;
  const okStroke = pathOuter2D(ctx, geom, scale);
  if (okStroke) ctx.stroke();

  if (item.type === 'countertop') {
    drawAssembledCutouts2D(ctx, item.cutouts, scale);
    drawAssembledSeams2D(ctx, item.seams, scale);
  }
  drawCenteredPieceLabel2D(ctx, geom, item.label || 'shape', scale, item.type);
  ctx.restore();
}

function draw2DRoomSectionLabels(ctx, sections, opts){
  if (!viewerSeparateRooms || !Array.isArray(sections) || !sections.length) return;
  const scale = Number(opts.scale) || 1;
  const pad = Number(opts.pad) || 0;
  const palette = viewer2DPalette();
  ctx.save();
  ctx.font = "700 13px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif";
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  sections.forEach((section) => {
    const label = String(section.label || '').trim();
    if (!label) return;
    const x = pad + ((Number(section.x) || 0) - opts.originX) * scale;
    const y = pad + ((Number(section.y) || 0) - opts.originY) * scale + 5;
    ctx.fillStyle = palette.roomBadgeBg;
    const tw = Math.ceil(ctx.measureText(label).width);
    roundRectPath2D(ctx, x - 7, y - 12, tw + 14, 24, 5);
    ctx.fill();
    ctx.strokeStyle = palette.roomBadgeBorder;
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.fillStyle = palette.roomBadgeText;
    ctx.fillText(label, x, y);
  });
  ctx.restore();
}

function drawCenteredPieceLabel2D(ctx, geom, label, scale, type){
  const text = String(label || '').trim();
  if (!text) return;
  const w = Math.max(1, Number(geom && geom.w) || 1) * scale;
  const h = Math.max(1, Number(geom && geom.h) || 1) * scale;
  const maxFont = type === 'countertop' ? 12 : 11;
  const minFont = type === 'countertop' ? 9 : 8;
  const estimated = text.length * 0.56;
  let fontSize = Math.min(maxFont, Math.max(minFont, (w - 8) / Math.max(1, estimated), h * 0.42));
  if (!Number.isFinite(fontSize)) fontSize = maxFont;
  const palette = viewer2DPalette();
  ctx.save();
  ctx.globalAlpha = type === 'backsplash' ? 0.92 : 1;
  ctx.font = `700 ${fontSize.toFixed(1)}px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.lineWidth = Math.max(2, fontSize * 0.28);
  ctx.strokeStyle = palette.labelHalo;
  ctx.fillStyle = palette.labelText;
  ctx.strokeText(text, 0, 0, Math.max(16, w - 6));
  ctx.fillText(text, 0, 0, Math.max(16, w - 6));
  ctx.restore();
}

function formatDimensionInches(v){
  const n = Number(v) || 0;
  if (Math.abs(n - Math.round(n)) < 0.05) return `${Math.round(n)}"`;
  return `${n.toFixed(1).replace(/\.0$/, '')}"`;
}

function rectsOverlap2D(a, b){
  return !!(a && b && a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y);
}

function rotatedRectAabb2D(cx, cy, w, h, angleRad){
  const c = Math.cos(angleRad);
  const s = Math.sin(angleRad);
  const hw = w / 2;
  const hh = h / 2;
  const pts = [
    {x: -hw, y: -hh},
    {x:  hw, y: -hh},
    {x:  hw, y:  hh},
    {x: -hw, y:  hh}
  ].map((p) => ({
    x: cx + p.x * c - p.y * s,
    y: cy + p.x * s + p.y * c
  }));
  const xs = pts.map((p) => p.x);
  const ys = pts.map((p) => p.y);
  const minX = Math.min.apply(null, xs);
  const maxX = Math.max.apply(null, xs);
  const minY = Math.min.apply(null, ys);
  const maxY = Math.max.apply(null, ys);
  return {x: minX, y: minY, w: maxX - minX, h: maxY - minY};
}

function dimensionLabelAngleFacingEdge2D(p1, p2, nx, ny){
  const edgeAngle = Math.atan2(p2.y - p1.y, p2.x - p1.x);
  const localBottom = {x: -Math.sin(edgeAngle), y: Math.cos(edgeAngle)};
  const towardEdge = {x: -nx, y: -ny};
  return (localBottom.x * towardEdge.x + localBottom.y * towardEdge.y) >= 0
    ? edgeAngle
    : edgeAngle + Math.PI;
}

function itemLocalToCanvas2D(item, x, y, opts){
  const scale = Number(opts.scale) || 1;
  const pad = Number(opts.pad) || 0;
  const p = assemblyWorldPoint(item.placement || {}, {x:0, y:0}, x, y);
  return {
    x: pad + (p.x - opts.originX) * scale,
    y: pad + (p.y - opts.originY) * scale
  };
}

function dimensionCandidateEdges2D(item){
  const geom = item && item.geom;
  const edges = geomEdgeSegments(geom);
  if (!edges.length) return [];
  if (geom && (geom.kind === 'rect' || geom.kind === 'oval')) return edges.filter((e) => e.index === 0 || e.index === 1);
  return edges;
}

function drawAssembledDimensions2D(ctx, item, opts){
  const state = opts && opts.dimensionState;
  if (!state) return;
  const geom = item && item.geom;
  const edges = dimensionCandidateEdges2D(item);
  if (!geom || !edges.length) return;
  const scale = Number(opts.scale) || 1;
  const rot = normDeg(item.placement?.rot || 0);
  const type = String(item.type || '');
  const minScreenLen = type === 'countertop' ? 52 : 58;
  const palette = viewer2DPalette();

  edges.forEach((edge) => {
    if (!edge || edge.len <= 0 || edge.len * scale < minScreenLen) return;
    const key = `${String(item.part?.id || item.label || type)}:${type}:edge:${edge.index}`;
    if (viewer2DHiddenDimensionKeys.has(key)) return;

    const p1 = itemLocalToCanvas2D(item, edge.a.x, edge.a.y, opts);
    const p2 = itemLocalToCanvas2D(item, edge.b.x, edge.b.y, opts);
    const edgeLenPx = Math.hypot(p2.x - p1.x, p2.y - p1.y);
    if (edgeLenPx < minScreenLen) return;
    const outwardLocal = localEdgeOutwardNormal(geom, edge);
    const outward = rotatePoint2D(outwardLocal.x, outwardLocal.y, rot);
    const nx = outward.x;
    const ny = outward.y;
    const dimOffset = type === 'countertop' ? 13 : 9;
    const labelOffset = dimOffset + 13;
    const lineA = {x: p1.x + nx * dimOffset, y: p1.y + ny * dimOffset};
    const lineB = {x: p2.x + nx * dimOffset, y: p2.y + ny * dimOffset};
    const label = formatDimensionInches(edge.len);
    ctx.save();
    ctx.font = "10px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif";
    const tw = Math.ceil(ctx.measureText(label).width);
    ctx.restore();
    const labelX = ((p1.x + p2.x) / 2) + nx * labelOffset;
    const labelY = ((p1.y + p2.y) / 2) + ny * labelOffset;
    const boxW = tw + 12;
    const boxH = 16;
    const labelAngle = dimensionLabelAngleFacingEdge2D(p1, p2, nx, ny);
    const box = Object.assign(rotatedRectAabb2D(labelX, labelY, boxW, boxH, labelAngle), {key});
    if ((state.occupied || []).some((other) => rectsOverlap2D(box, other))) return;

    ctx.save();
    ctx.strokeStyle = type === 'countertop' ? palette.dimLine : palette.dimLineMuted;
    ctx.fillStyle = palette.labelText;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(lineA.x, lineA.y);
    ctx.lineTo(lineB.x, lineB.y);
    ctx.moveTo(p1.x + nx * (dimOffset - 4), p1.y + ny * (dimOffset - 4));
    ctx.lineTo(p1.x + nx * (dimOffset + 4), p1.y + ny * (dimOffset + 4));
    ctx.moveTo(p2.x + nx * (dimOffset - 4), p2.y + ny * (dimOffset - 4));
    ctx.lineTo(p2.x + nx * (dimOffset + 4), p2.y + ny * (dimOffset + 4));
    ctx.stroke();
    ctx.fillStyle = palette.dimBadgeBg;
    ctx.strokeStyle = palette.dimBadgeBorder;
    ctx.lineWidth = 1;
    ctx.translate(labelX, labelY);
    ctx.rotate(labelAngle);
    roundRectPath2D(ctx, -boxW / 2, -boxH / 2, boxW, boxH, 4);
    ctx.fill();
    ctx.stroke();
    ctx.fillStyle = type === 'countertop' ? palette.dimText : palette.dimTextMuted;
    ctx.font = "700 10px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif";
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, 0, 0);
    ctx.restore();

    state.occupied.push(box);
    state.hitboxes.push(box);
  });
}

function rerender2DViewer(){
  if (typeof render2D === 'function' && SNAPSHOT) {
    render2D(viewerActiveSnapshot());
    notifyViewerReady('2d');
  }
}

function on2DCanvasClick(event){
  if (!viewer2DDimensionsOn || !viewer2DDimensionHitboxes.length) return;
  const canvas = event.currentTarget;
  if (!canvas || !canvas.getBoundingClientRect) return;
  const rect = canvas.getBoundingClientRect();
  const x = (event.clientX - rect.left) * (canvas.width / Math.max(1, rect.width));
  const y = (event.clientY - rect.top) * (canvas.height / Math.max(1, rect.height));
  for (let i = viewer2DDimensionHitboxes.length - 1; i >= 0; i--) {
    const box = viewer2DDimensionHitboxes[i];
    if (!box || !box.key) continue;
    if (x >= box.x && x <= box.x + box.w && y >= box.y && y <= box.y + box.h) {
      viewer2DHiddenDimensionKeys.add(box.key);
      rerender2DViewer();
      return;
    }
  }
}

function sync2DControls(){
  const btn = $('#btn-dimensions');
  if (btn) {
    btn.textContent = viewer2DDimensionsOn ? 'Dimensions: On' : 'Dimensions: Off';
    btn.title = viewer2DDimensionsOn
      ? 'Click a dimension label in 2D to hide that selected dimension.'
      : 'Show 2D dimension labels.';
    btn.style.borderColor = viewer2DDimensionsOn ? 'rgba(245,158,11,.72)' : '';
    btn.style.boxShadow = viewer2DDimensionsOn ? '0 0 0 2px rgba(245,158,11,.15) inset' : '';
  }
  const reset = $('#btn-dim-reset');
  if (reset) {
    reset.style.display = viewer2DHiddenDimensionKeys.size ? '' : 'none';
    reset.title = 'Show hidden 2D dimension labels again.';
  }
}

function drawAssembledTextureFill(ctx, geom, part, stone, imgUrl, scale){
  if (!imgUrl) return false;
  window.__sheetTextureCache = window.__sheetTextureCache || {};
  window.__sheetTexturePending = window.__sheetTexturePending || {};
  const cached = window.__sheetTextureCache[imgUrl];
  if (!cached) {
    if (!window.__sheetTexturePending[imgUrl]) {
      window.__sheetTexturePending[imgUrl] = true;
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function(){
        window.__sheetTextureCache[imgUrl] = img;
        delete window.__sheetTexturePending[imgUrl];
        if (typeof render2D === 'function' && typeof SNAPSHOT !== 'undefined' && SNAPSHOT) {
          render2D(viewerActiveSnapshot());
          notifyViewerReady('2d');
        }
      };
      img.onerror = function(){ delete window.__sheetTexturePending[imgUrl]; };
      img.src = imgUrl;
    }
    return false;
  }
  const ok = pathOuter2D(ctx, geom, scale);
  if (!ok) return false;
  ctx.save();
  ctx.clip();
  const size = viewerSheetSize(stone, part.stoneKey || '', Number(part.placement?.slabIndex) || 0);
  const center = layoutPlacementCenter(part);
  const rot = normDeg(part.placement?.rot || 0);
  const slabW = Math.max(1, size.slabW);
  const slabH = Math.max(1, size.slabH);
  ctx.globalAlpha = 0.96;
  ctx.rotate((-rot * Math.PI) / 180);
  ctx.drawImage(cached, -center.x * scale, -center.y * scale, slabW * scale, slabH * scale);
  ctx.restore();
  return true;
}

function drawAssembledCutouts2D(ctx, cutouts, scale){
  const rows = Array.isArray(cutouts) ? cutouts : [];
  if (!rows.length) return;
  const palette = viewer2DPalette();
  ctx.save();
  ctx.globalCompositeOperation = 'destination-out';
  ctx.globalAlpha = 1;
  rows.forEach((c) => {
    if (rotatedRoundRectPath2D(ctx, c, scale)) ctx.fill();
  });
  ctx.restore();

  ctx.save();
  ctx.strokeStyle = palette.cutout;
  ctx.lineWidth = 1.25;
  ctx.setLineDash([6, 4]);
  rows.forEach((c) => {
    if (rotatedRoundRectPath2D(ctx, c, scale)) ctx.stroke();
  });
  ctx.setLineDash([]);
  ctx.restore();
}

function drawAssembledSeams2D(ctx, seams, scale){
  const rows = Array.isArray(seams) ? seams : [];
  if (!rows.length) return;
  const palette = viewer2DPalette();
  ctx.save();
  ctx.strokeStyle = palette.seam;
  ctx.lineWidth = 2;
  ctx.setLineDash([10, 6]);
  rows.forEach((s) => {
    ctx.beginPath();
    ctx.moveTo((Number(s.x1) || 0) * scale, (Number(s.y1) || 0) * scale);
    ctx.lineTo((Number(s.x2) || 0) * scale, (Number(s.y2) || 0) * scale);
    ctx.stroke();
  });
  ctx.setLineDash([]);
  ctx.restore();
}

function drawPart2D(ctx, part, pad, scale){
  const g = part.geom;
  const rot = normDeg(part.placement?.rot || 0);
  const w = Number(g?.w) || 0;
  const h = Number(g?.h) || 0;
  const bb = rotatedBBox(w, h, rot);
  const x = pad + (Number(part.placement?.x) || 0) * scale;
  const y = pad + (Number(part.placement?.y) || 0) * scale;
  const cx = x + (bb.bw * scale) / 2;
  const cy = y + (bb.bh * scale) / 2;

  ctx.save();
  ctx.translate(cx, cy);
  ctx.rotate((rot * Math.PI) / 180);

  const palette = viewer2DPalette();
  const partColor = part.partType === 'backsplash' ? palette.backsplash : (part.partType === 'waterfall' ? palette.waterfall : palette.countertop);
  ctx.fillStyle = partColor;
  ctx.strokeStyle = partColor;
  ctx.lineWidth = 2;

  const ok = pathOuter2D(ctx, g, scale);
  if (ok) {
    ctx.globalAlpha = (part.partType === 'backsplash' || part.partType === 'waterfall') ? 0.18 : 0.16;
    ctx.fill();
    ctx.globalAlpha = 0.9;
    ctx.stroke();
  }

  // Holes
  const cutouts = Array.isArray(part.cutouts) ? part.cutouts : [];
  if (cutouts.length) {
    ctx.save();
    ctx.globalCompositeOperation = 'destination-out';
    ctx.globalAlpha = 1;
    cutouts.forEach((c) => {
      if (rotatedRoundRectPath2D(ctx, c, scale)) ctx.fill();
    });
    ctx.restore();

    ctx.save();
    ctx.globalAlpha = 1;
    ctx.strokeStyle = palette.cutout;
    ctx.lineWidth = 1;
    ctx.setLineDash([6, 4]);
    cutouts.forEach((c) => {
      if (rotatedRoundRectPath2D(ctx, c, scale)) ctx.stroke();
    });
    ctx.setLineDash([]);
    ctx.restore();
  }

  // Seams
  const seams = Array.isArray(part.seams) ? part.seams : [];
  if (seams.length) {
    ctx.save();
    ctx.globalAlpha = 1;
    ctx.strokeStyle = palette.seam;
    ctx.lineWidth = 2;
    ctx.setLineDash([10, 6]);
    seams.forEach((s) => {
      ctx.beginPath();
      ctx.moveTo((Number(s.x1) || 0) * scale, (Number(s.y1) || 0) * scale);
      ctx.lineTo((Number(s.x2) || 0) * scale, (Number(s.y2) || 0) * scale);
      ctx.stroke();
    });
    ctx.setLineDash([]);
    ctx.restore();
  }

  drawCenteredPieceLabel2D(ctx, g, part.label || 'shape', scale, part.partType || 'countertop');
  ctx.restore();
}

function pathOuter2D(ctx, g, scale){
  if (!g) return false;
  if (g.kind === 'rect') {
    ctx.beginPath();
    ctx.rect((-g.w * scale) / 2, (-g.h * scale) / 2, g.w * scale, g.h * scale);
    return true;
  }
  if (g.kind === 'oval') {
    ctx.beginPath();
    ctx.ellipse(0, 0, (g.w * scale) / 2, (g.h * scale) / 2, 0, 0, Math.PI * 2);
    return true;
  }
  if (g.kind === 'poly') {
    const pts = Array.isArray(g.pts) ? g.pts : [];
    if (!pts.length) return false;
    ctx.beginPath();
    ctx.moveTo((pts[0][0] - g.w / 2) * scale, (pts[0][1] - g.h / 2) * scale);
    for (let i = 1; i < pts.length; i++) ctx.lineTo((pts[i][0] - g.w / 2) * scale, (pts[i][1] - g.h / 2) * scale);
    ctx.closePath();
    return true;
  }
  return false;
}

function escapeHtml(s){
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function build3D(snapshot){
  const canvas = $('#three');
  if (!canvas) return null;

  const renderer = new THREE.WebGLRenderer({canvas, antialias:true, preserveDrawingBuffer:true});
  renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));

  const scene = new THREE.Scene();

  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100000);
  camera.position.set(0, 120, 220);

  const controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.screenSpacePanning = true;
  const defaultMouseButtons = {
    LEFT: THREE.MOUSE.ROTATE,
    MIDDLE: THREE.MOUSE.DOLLY,
    RIGHT: THREE.MOUSE.PAN,
  };
  controls.mouseButtons = { ...defaultMouseButtons };

  function targetIsEditableFocus(el){
    if (!el || !el.tagName) return false;
    const tag = String(el.tagName).toLowerCase();
    if (tag === 'textarea' || tag === 'select') return true;
    if (tag === 'input') {
      const t = String(el.type || '').toLowerCase();
      if (t === 'button' || t === 'submit' || t === 'checkbox' || t === 'radio') return false;
      return true;
    }
    return !!el.isContentEditable;
  }

  function setSpacePanMode(on){
    controls.mouseButtons = on
      ? {
          LEFT: THREE.MOUSE.PAN,
          MIDDLE: THREE.MOUSE.DOLLY,
          RIGHT: THREE.MOUSE.PAN,
        }
      : { ...defaultMouseButtons };
    canvas.style.cursor = on ? 'grab' : '';
  }

  function onSpacePanKeyDown(e){
    if (e.code !== 'Space' || e.repeat) return;
    if (targetIsEditableFocus(document.activeElement)) return;
    e.preventDefault();
    setSpacePanMode(true);
  }
  function onSpacePanKeyUp(e){
    if (e.code !== 'Space') return;
    setSpacePanMode(false);
  }
  function onSpacePanBlur(){
    setSpacePanMode(false);
  }
  window.addEventListener('keydown', onSpacePanKeyDown, true);
  window.addEventListener('keyup', onSpacePanKeyUp, true);
  window.addEventListener('blur', onSpacePanBlur);

  const hemi = new THREE.HemisphereLight(0xffffff, 0x223355, 0.95);
  scene.add(hemi);
  const dir = new THREE.DirectionalLight(0xffffff, 0.85);
  dir.position.set(200, 260, 140);
  scene.add(dir);
  const dirFill = new THREE.DirectionalLight(0xffffff, 0.38);
  dirFill.position.set(-220, 140, -180);
  scene.add(dirFill);

  // Ground grid
  const grid = new THREE.GridHelper(2000, 40, 0x334155, 0x1e293b);
  grid.position.y = -0.01;
  grid.visible = false;
  scene.add(grid);

  const {stonesByKey, byStone} = groupSnapshot(snapshot);
  const assembly = snapshot && typeof snapshot.assembly === 'object' ? snapshot.assembly : null;
  const assemblyMatchesArr = Array.isArray(assembly?.matches) ? assembly.matches : [];
  const assemblyParts = (Array.isArray(snapshot.parts) ? snapshot.parts : []).filter((p) => p && p.partType === 'countertop' && p.assemblyPlacement);
  /** Layout fallback stacks every slab column at X=0 when Assembly has connections (unrelated to slab identity). */
  const collapseLayoutSlabGap = !viewerSeparateRooms && assemblyMatchesArr.length > 0;
  const assemblyBacksplashPartsRaw = (Array.isArray(snapshot.parts) ? snapshot.parts : []).filter((p) => p && isBacksplashPart(p) && p.assemblyPlacement);
  /** `assembly.splashes` already builds the edge-attached mesh; layout-import backsplash parts share ids via layoutPartIdForAssemblySplash — skip duplicates or we render two (one floats at rect center). */
  const assemblySplashCoveredPartIds = new Set(
    (Array.isArray(assembly?.splashes) ? assembly.splashes : []).map((s) => String(layoutPartIdForAssemblySplash(s.id)))
  );
  const assemblyBacksplashParts = assemblyBacksplashPartsRaw.filter(
    (p) => !assemblySplashCoveredPartIds.has(String(p.id))
  );

  const slabGapX = 20;
  const stoneGapZ = 50;
  const textureCache = {};
  const allTargets = [];
  const modelObjects = [];

  function textureFor(url){
    const key = String(url || '');
    if (!key) return null;
    if (textureCache[key]) return textureCache[key];
    const loader = new THREE.TextureLoader();
    loader.setCrossOrigin('anonymous');
    const tex = loader.load(key, () => { if (renderer) renderer.render(scene, camera); });
    textureCache[key] = tex;
    return tex;
  }

  const assemblySplashCount = Array.isArray(assembly?.splashes) ? assembly.splashes.length : 0;
  const assemblyWaterfallCount = Array.isArray(assembly?.waterfalls) ? assembly.waterfalls.length : 0;
  if (assemblyParts.length || assemblyBacksplashParts.length || assemblySplashCount || assemblyWaterfallCount) {
    const roomOffsets = computeAssemblyRoomOffsets(assemblyParts.concat(assemblyBacksplashParts), {
      collapseToSingleOrigin: !viewerSeparateRooms && assemblyMatchesArr.length > 0
    });
    const allPartsById = new Map((Array.isArray(snapshot.parts) ? snapshot.parts : []).map((p) => [String(p.id), p]));

    assemblyParts.forEach((p) => {
      const stone = stonesByKey.get(String(p.stoneKey));
      const size = viewerSheetSize(stone, p.stoneKey, Number(p.placement?.slabIndex) || 0);
      const slabW = size.slabW;
      const slabH = size.slabH;
      const thickness = parseThicknessInches(stone?.tk || '3cm');
      const layoutRot = normDeg(p.placement?.rot || 0);
      const assemblyRot = normDeg(p.assemblyPlacement?.rot || 0);
      // Match Layout tab slab UVs: per-sheet placement + nominal slab size (same as render2D).
      const center = layoutPlacementCenter(p);
      const slabCenterX = center.x;
      const slabCenterY = center.y;
      const roomOffset = roomOffsets.get(String(p.assemblyPlacement?.roomId ?? p.roomId ?? 'room')) || {x:0, y:0};

      const {shape} = geomToOuterPath(p.geom);
      addCutoutHoles(shape, p.cutouts);

      const extrude = new THREE.ExtrudeGeometry(shape, {
        depth: thickness,
        bevelEnabled: true,
        bevelThickness: 0.08,
        bevelSize: 0.12,
        bevelOffset: 0,
        bevelSegments: 2,
        curveSegments: 24,
      });
      // Match assembly iframe Y-down vs Three.js profile Y-up without breaking the deck: mirror profile, then fix normals.
      extrude.scale(1, -1, 1);
      applySlabUVs(extrude, {
        slabW,
        slabH,
        centerX: slabCenterX,
        centerY: slabCenterY,
        rot: layoutRot
      });
      extrude.computeVertexNormals();

      const ctImgEntry = linkedSlabForSheet(p.stoneKey || '', Number(p.placement?.slabIndex) || 0);
      const ctImgSrc = ctImgEntry ? ctImgEntry.imageUrl : null;
      let topMat = null;
      if (ctImgSrc) {
        const baseTex = textureFor(ctImgSrc);
        if (baseTex) {
          const tex = baseTex.clone();
          tex.needsUpdate = true;
          tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
          tex.repeat.set(1, 1);
          tex.offset.set(0, 0);
          if ('colorSpace' in tex && THREE.SRGBColorSpace) tex.colorSpace = THREE.SRGBColorSpace;
          // scale(1,-1,1) inverts cap winding; DoubleSide keeps the deck visible from above (slab is not "flipped" in UV — only rotation).
          topMat = new THREE.MeshStandardMaterial({map: tex, roughness: 0.35, metalness: 0.08, side: THREE.DoubleSide});
        }
      }
      if (!topMat) topMat = new THREE.MeshStandardMaterial({color: 0x93c5fd, roughness: 0.55, metalness: 0.02, side: THREE.DoubleSide});
      const sideMat = new THREE.MeshStandardMaterial({
        color: 0x52525b,
        roughness: 0.88,
        metalness: 0.06,
        transparent: false,
        opacity: 1,
        depthWrite: true,
        side: THREE.DoubleSide
      });
      const mesh = new THREE.Mesh(extrude, [topMat, sideMat]);
      mesh.rotation.x = -Math.PI / 2;
      mesh.rotation.z = assemblyCssRotToPlanRad(assemblyRot);
      const awx = (Number(roomOffset.x) || 0) + (Number(p.assemblyPlacement?.x) || 0);
      const awy = (Number(roomOffset.y) || 0) + (Number(p.assemblyPlacement?.y) || 0);
      mesh.position.set(awx, 0.06, awy);
      scene.add(mesh);
      modelObjects.push(mesh);
      allTargets.push(mesh.position);

      const seams = Array.isArray(p.seams) ? p.seams : [];
      seams.forEach((s) => {
        const x1 = Number(s.x1) || 0;
        const y1 = Number(s.y1) || 0;
        const x2 = Number(s.x2) || 0;
        const y2 = Number(s.y2) || 0;
        const pts = [
          new THREE.Vector3(x1, -y1, thickness + 0.03),
          new THREE.Vector3(x2, -y2, thickness + 0.03),
        ];
        const geo = new THREE.BufferGeometry().setFromPoints(pts);
        const line = new THREE.Line(geo, new THREE.LineDashedMaterial({color: 0xf59e0b, dashSize: 4, gapSize: 3}));
        line.computeLineDistances();
        line.rotation.x = -Math.PI / 2;
        line.rotation.z = assemblyCssRotToPlanRad(assemblyRot);
        line.position.copy(mesh.position);
        scene.add(line);
        modelObjects.push(line);
      });
    });

    assemblyBacksplashParts.forEach((p) => {
      const stone = stonesByKey.get(String(p.stoneKey));
      const thickness = parseThicknessInches(stone?.tk || '3cm');
      const splashW = Number(p.geom?.w) || 0;
      const splashH = Number(p.geom?.h) || 0;
      if (splashW <= 0 || splashH <= 0) return;
      const assemblyRot = normDeg(p.assemblyPlacement?.rot || 0);
      const roomOffset = roomOffsets.get(String(p.assemblyPlacement?.roomId ?? p.roomId ?? 'room')) || {x:0, y:0};
      const ctImgEntry = linkedSlabForSheet(p.stoneKey || '', Number(p.placement?.slabIndex) || 0);
      const ctImgSrc = ctImgEntry ? ctImgEntry.imageUrl : null;
      const size = viewerSheetSize(stone, p.stoneKey, Number(p.placement?.slabIndex) || 0);
      const slabW = size.slabW;
      const slabH = size.slabH;
      const lc = layoutPlacementCenter(p);
      const boxGeo = new THREE.BoxGeometry(splashW, splashH, thickness);
      applySlabUVsToBacksplashBox(boxGeo, {
        slabW,
        slabH,
        centerX: lc.x,
        centerY: lc.y,
        rot: normDeg(p.placement?.rot || 0),
        halfThickness: thickness / 2
      });
      let mat = null;
      if (ctImgSrc) {
        const baseTex = textureFor(ctImgSrc);
        if (baseTex) {
          const tex = baseTex.clone();
          tex.needsUpdate = true;
          tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
          tex.repeat.set(1, 1);
          tex.offset.set(0, 0);
          if ('colorSpace' in tex && THREE.SRGBColorSpace) tex.colorSpace = THREE.SRGBColorSpace;
          mat = new THREE.MeshStandardMaterial({
            map: tex,
            roughness: 0.35,
            metalness: 0.08,
            side: THREE.DoubleSide
          });
        }
      }
      if (!mat) mat = new THREE.MeshStandardMaterial({color: 0xa7f3d0, roughness: 0.55, metalness: 0.02, side: THREE.DoubleSide});
      const mesh = new THREE.Mesh(boxGeo, mat);
      const ax = (Number(roomOffset.x) || 0) + (Number(p.assemblyPlacement?.x) || 0);
      const awy = (Number(roomOffset.y) || 0) + (Number(p.assemblyPlacement?.y) || 0);
      mesh.position.set(ax, thickness + 0.06 + splashH / 2, awy);
      mesh.rotation.y = assemblyCssRotToPlanRad(assemblyRot);
      scene.add(mesh);
      modelObjects.push(mesh);
      allTargets.push(mesh.position);
    });

    const matches = Array.isArray(assembly?.matches) ? assembly.matches : [];
    matches.forEach((match) => {
      const partA = allPartsById.get(String(match.partAId));
      const partB = allPartsById.get(String(match.partBId));
      if (!partA || !partB || !partA.assemblyPlacement || !partB.assemblyPlacement) return;
      const roomOffset = roomOffsets.get(String(partA.assemblyPlacement?.roomId ?? partA.roomId ?? 'room')) || {x:0, y:0};
      const edgeA = getAssemblyWorldEdge(partA, match.edgeAIndex, roomOffset);
      const edgeB = getAssemblyWorldEdge(partB, match.edgeBIndex, roomOffset);
      if (!edgeA || !edgeB) return;
      const half = Math.min(edgeA.len, edgeB.len) / 2;
      const ux = Math.cos(edgeA.angle);
      const uy = Math.sin(edgeA.angle);
      const t0 = (edgeA.len - half * 2) / 2;
      const sx = edgeA.start.x + ux * t0;
      const sy = edgeA.start.y + uy * t0;
      const ex = edgeA.start.x + ux * (t0 + half * 2);
      const ey = edgeA.start.y + uy * (t0 + half * 2);
      const start = new THREE.Vector3(sx, 0.2, sy);
      const end = new THREE.Vector3(ex, 0.2, ey);
      const geo = new THREE.BufferGeometry().setFromPoints([start, end]);
      const line = new THREE.Line(geo, new THREE.LineDashedMaterial({
        color: (Number(match.lengthDelta) || 0) > 0.25 ? 0xef4444 : 0xfde68a,
        dashSize: 4,
        gapSize: 3
      }));
      line.computeLineDistances();
      scene.add(line);
      modelObjects.push(line);
    });

    const splashes = Array.isArray(assembly?.splashes) ? assembly.splashes : [];
    splashes.forEach((splash) => {
      const host = allPartsById.get(String(splash.hostPartId));
      if (!host || !host.assemblyPlacement) return;
      const stone = stonesByKey.get(String(host.stoneKey));
      const counterThickness = parseThicknessInches(stone?.tk || '3cm');
      const roomOffset = roomOffsets.get(String(host.assemblyPlacement?.roomId ?? host.roomId ?? 'room')) || {x:0, y:0};
      const worldEdge = getAssemblyWorldEdge(host, splash.edgeIndex, roomOffset);
      if (!worldEdge || worldEdge.len <= 0) return;
      const len = clamp(Number(splash.length) || 0, 0, worldEdge.len);
      const offset = clamp(Number(splash.offset) || 0, 0, Math.max(0, worldEdge.len - len));
      // Same convention as shape-connector layoutSplashShapesFromHosts: center along edge at
      // offset + len/2 + offsetIn from worldEdge.start (positive offsetIn follows edge start→end).
      const userOffsetIn = Number(splash.offsetIn) || 0;
      const height = Math.max(0, Number(splash.height) || 0);
      if (len <= 0 || height <= 0) return;
      const thickness = Math.max(0.1, Number(splash.thickness) || counterThickness);
      const rc = Number(splash.revealCm);
      const revealCm = rc === 1 || rc === 2 || rc === 3 ? rc : 3;
      const revealIn = revealCm / 2.54;
      const depthIn = Math.max(thickness, revealIn);
      const ux = (worldEdge.end.x - worldEdge.start.x) / worldEdge.len;
      const uy = (worldEdge.end.y - worldEdge.start.y) / worldEdge.len;
      const edgeLocal = geomEdgeSegments(host.geom).find((e) => e.index === splash.edgeIndex);
      if (!edgeLocal || edgeLocal.len <= 0) return;
      const inwardLoc = localEdgeInteriorNormal(host.geom, edgeLocal);
      const inwardW = rotatePoint2D(inwardLoc.x, inwardLoc.y, normDeg(host.assemblyPlacement?.rot || 0));
      // Match shape-connector: center along host edge at offset + len/2 + offsetIn from worldEdge.start.
      const midT = (Number(offset) || 0) + (Number(len) || 0) / 2 + (Number(userOffsetIn) || 0);
      const mid = {
        x: worldEdge.start.x + ux * midT,
        y: worldEdge.start.y + uy * midT
      };
      const centerX = mid.x + inwardW.x * (depthIn / 2);
      const centerZ = mid.y + inwardW.y * (depthIn / 2);
      const centerY = counterThickness + 0.06 + (height / 2);

      // Layout (x,y) maps to Three.js (x,z); Y is up. Box: local X = along edge, Y = height (world up), Z = depth inward.
      // Must stay right-handed (X×Y=Z). Z = X×up with X along edge; never negate Z alone to match interior — that
      // yields det=-1 and breaks vertical when converting to quaternion. If interior is opposite tan×up, flip X instead.
      const up = new THREE.Vector3(0, 1, 0);
      const innDesired = new THREE.Vector3(inwardW.x, 0, inwardW.y);
      let xAxis = new THREE.Vector3(ux, 0, uy).normalize();
      let zAxis = new THREE.Vector3().crossVectors(xAxis, up);
      if (zAxis.lengthSq() < 1e-16) return;
      zAxis.normalize();
      if (zAxis.dot(innDesired) < 0) {
        xAxis.negate();
        zAxis.crossVectors(xAxis, up).normalize();
      }
      const orient = new THREE.Matrix4();
      orient.makeBasis(xAxis, up, zAxis);
      const orientQuat = new THREE.Quaternion();
      orientQuat.setFromRotationMatrix(orient);

      const splashPart = allPartsById.get(layoutPartIdForAssemblySplash(splash.id));
      const splashImgEntry = splashPart ? linkedSlabForSheet(splashPart.stoneKey || '', Number(splashPart.placement?.slabIndex) || 0) : null;
      const splashImgSrc = splashImgEntry ? splashImgEntry.imageUrl : null;
      const size = viewerSheetSize(stone, splashPart?.stoneKey || '', Number(splashPart?.placement?.slabIndex) || 0);
      const slabW = size.slabW;
      const slabH = size.slabH;
      const splashLayoutRot = splashPart ? normDeg(splashPart.placement?.rot || 0) : 0;
      const splashCenter = splashPart ? layoutPlacementCenter(splashPart) : {x: 0, y: 0};
      const splashBoxGeo = new THREE.BoxGeometry(len, height, depthIn);
      if (splashPart && splashImgSrc) {
        applySlabUVsToBacksplashBox(splashBoxGeo, {
          slabW,
          slabH,
          centerX: splashCenter.x,
          centerY: splashCenter.y,
          rot: splashLayoutRot,
          halfThickness: depthIn / 2
        });
      }
      let splashMat;
      if (splashPart && splashImgSrc) {
        const baseTex = textureFor(splashImgSrc);
        if (baseTex) {
          const tex = baseTex.clone();
          tex.needsUpdate = true;
          tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
          tex.repeat.set(1, 1);
          tex.offset.set(0, 0);
          if ('colorSpace' in tex && THREE.SRGBColorSpace) tex.colorSpace = THREE.SRGBColorSpace;
          splashMat = new THREE.MeshStandardMaterial({map: tex, roughness: 0.35, metalness: 0.06, side: THREE.DoubleSide});
        }
      }
      if (!splashMat) {
        splashMat = new THREE.MeshStandardMaterial({color: 0x64748b, roughness: 0.82, metalness: 0.02, side: THREE.DoubleSide});
      }
      const splashBox = new THREE.Mesh(splashBoxGeo, splashMat);
      splashBox.position.set(centerX, centerY, centerZ);
      splashBox.quaternion.copy(orientQuat);
      scene.add(splashBox);
      modelObjects.push(splashBox);
    });

    const waterfalls = Array.isArray(assembly?.waterfalls) ? assembly.waterfalls : [];
    waterfalls.forEach((waterfall) => {
      const host = allPartsById.get(String(waterfall.hostPartId));
      if (!host || !host.assemblyPlacement) return;
      const stone = stonesByKey.get(String(host.stoneKey));
      const counterThickness = parseThicknessInches(stone?.tk || '3cm');
      const roomOffset = roomOffsets.get(String(host.assemblyPlacement?.roomId ?? host.roomId ?? 'room')) || {x:0, y:0};
      const worldEdge = getAssemblyWorldEdge(host, waterfall.edgeIndex, roomOffset);
      if (!worldEdge || worldEdge.len <= 0) return;
      const len = clamp(Number(waterfall.length) || 0, 0, worldEdge.len);
      const offset = clamp(Number(waterfall.offset) || 0, 0, Math.max(0, worldEdge.len - len));
      const userOffsetIn = Number(waterfall.offsetIn) || 0;
      const height = Math.max(0, Number(waterfall.height) || 0);
      if (len <= 0 || height <= 0) return;
      const depthIn = Math.max(0.1, Number(waterfall.thickness) || counterThickness);
      const ux = (worldEdge.end.x - worldEdge.start.x) / worldEdge.len;
      const uy = (worldEdge.end.y - worldEdge.start.y) / worldEdge.len;
      const edgeLocal = geomEdgeSegments(host.geom).find((e) => e.index === waterfall.edgeIndex);
      if (!edgeLocal || edgeLocal.len <= 0) return;
      const inwardLoc = localEdgeInteriorNormal(host.geom, edgeLocal);
      const inwardW = rotatePoint2D(inwardLoc.x, inwardLoc.y, normDeg(host.assemblyPlacement?.rot || 0));
      const midT = (Number(offset) || 0) + (Number(len) || 0) / 2 + userOffsetIn;
      const mid = {
        x: worldEdge.start.x + ux * midT,
        y: worldEdge.start.y + uy * midT
      };
      const centerX = mid.x + inwardW.x * (depthIn / 2);
      const centerZ = mid.y + inwardW.y * (depthIn / 2);
      // Countertop mesh sits just above y=0; waterfall begins at the countertop bottom and drops toward the floor.
      const centerY = 0.06 - (height / 2);

      const down = new THREE.Vector3(0, -1, 0);
      const innDesired = new THREE.Vector3(inwardW.x, 0, inwardW.y);
      let xAxis = new THREE.Vector3(ux, 0, uy).normalize();
      let zAxis = new THREE.Vector3().crossVectors(xAxis, down);
      if (zAxis.lengthSq() < 1e-16) return;
      zAxis.normalize();
      if (zAxis.dot(innDesired) < 0) {
        xAxis.negate();
        zAxis.crossVectors(xAxis, down).normalize();
      }
      const orient = new THREE.Matrix4();
      orient.makeBasis(xAxis, down, zAxis);
      const orientQuat = new THREE.Quaternion();
      orientQuat.setFromRotationMatrix(orient);

      const waterfallPart = allPartsById.get(String(waterfall.id));
      const waterfallImgEntry = waterfallPart ? linkedSlabForSheet(waterfallPart.stoneKey || '', Number(waterfallPart.placement?.slabIndex) || 0) : null;
      const waterfallImgSrc = waterfallImgEntry ? waterfallImgEntry.imageUrl : null;
      const size = viewerSheetSize(stone, waterfallPart?.stoneKey || '', Number(waterfallPart?.placement?.slabIndex) || 0);
      const waterfallLayoutRot = waterfallPart ? normDeg(waterfallPart.placement?.rot || 0) : 0;
      const waterfallCenter = waterfallPart ? layoutPlacementCenter(waterfallPart) : {x: 0, y: 0};
      const waterfallBoxGeo = new THREE.BoxGeometry(len, height, depthIn);
      if (waterfallPart && waterfallImgSrc) {
        applySlabUVsToBacksplashBox(waterfallBoxGeo, {
          slabW: size.slabW,
          slabH: size.slabH,
          centerX: waterfallCenter.x,
          centerY: waterfallCenter.y,
          rot: waterfallLayoutRot,
          halfThickness: depthIn / 2
        });
      }
      let waterfallMat;
      if (waterfallPart && waterfallImgSrc) {
        const baseTex = textureFor(waterfallImgSrc);
        if (baseTex) {
          const tex = baseTex.clone();
          tex.needsUpdate = true;
          tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
          tex.repeat.set(1, 1);
          tex.offset.set(0, 0);
          if ('colorSpace' in tex && THREE.SRGBColorSpace) tex.colorSpace = THREE.SRGBColorSpace;
          waterfallMat = new THREE.MeshStandardMaterial({map: tex, roughness: 0.35, metalness: 0.06, side: THREE.DoubleSide});
        }
      }
      if (!waterfallMat) {
        waterfallMat = new THREE.MeshStandardMaterial({color: 0x256d85, roughness: 0.82, metalness: 0.02, side: THREE.DoubleSide});
      }
      const waterfallBox = new THREE.Mesh(waterfallBoxGeo, waterfallMat);
      waterfallBox.position.set(centerX, centerY, centerZ);
      waterfallBox.quaternion.copy(orientQuat);
      scene.add(waterfallBox);
      modelObjects.push(waterfallBox);
    });
  } else {

  let stoneRow = 0;

  for (const [stoneKey, parts] of byStone.entries()) {
    const stone = stonesByKey.get(String(stoneKey));
    const thickness = parseThicknessInches(stone?.tk || '3cm');
    const slabCount = effectiveSlabCountForViewer(stone, parts);

    for (let slabIdx = 0; slabIdx < slabCount; slabIdx++) {
      const size = viewerSheetSize(stone, stoneKey, slabIdx);
      const slabW = size.slabW;
      const slabH = size.slabH;
      const slabOriginX = collapseLayoutSlabGap ? 0 : (slabIdx * (slabW + slabGapX));
      const slabOriginZ = stoneRow * (slabH + stoneGapZ);

      // Parts on this slab
      parts
        .filter((p) => (Number(p.placement?.slabIndex) || 0) === slabIdx)
        .forEach((p) => {
          const g = p.geom;
          const rot = normDeg(p.placement?.rot || 0);
          const center = layoutPlacementCenter(p);
          const xCenter = center.x;
          const yCenter = center.y;
          const xWorld = (xCenter - slabW / 2) + slabOriginX;
          const zWorld = (yCenter - slabH / 2) + slabOriginZ;
          const isBacksplash = isBacksplashPart(p);
          const isWaterfall = isWaterfallPart(p);

          const ctImgEntry = linkedSlabForSheet(p.stoneKey || '', Number(p.placement?.slabIndex) || 0);
          const ctImgSrc = ctImgEntry ? ctImgEntry.imageUrl : null;
          let topMat;
          if (ctImgSrc) {
            const baseTex = textureFor(ctImgSrc);
            if (baseTex) {
              const tex = baseTex.clone();
              tex.needsUpdate = true;
              tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
              tex.repeat.set(1, 1);
              tex.offset.set(0, 0);
              if ('colorSpace' in tex && THREE.SRGBColorSpace) tex.colorSpace = THREE.SRGBColorSpace;
              topMat = new THREE.MeshStandardMaterial({map: tex, roughness: 0.35, metalness: 0.08});
            }
          }
          if (!topMat) {
            topMat = new THREE.MeshStandardMaterial({
              color: isBacksplash ? 0xa7f3d0 : (isWaterfall ? 0x38bdf8 : 0x93c5fd),
              roughness: 0.55,
              metalness: 0.02
            });
          }
          const sideMat = new THREE.MeshStandardMaterial({
            color: isBacksplash ? 0x5b8b7a : (isWaterfall ? 0x256d85 : 0x52525b),
            roughness: 0.88,
            metalness: 0.06,
            transparent: false,
            opacity: 1,
            depthWrite: true,
            side: THREE.DoubleSide
          });

          let mesh;
          if (isBacksplash || isWaterfall) {
            const splashW = Number(g.w) || 0;
            const splashH = Number(g.h) || 0;
            const boxGeo = new THREE.BoxGeometry(splashW, splashH, thickness);
            applySlabUVsToBacksplashBox(boxGeo, {
              slabW,
              slabH,
              centerX: xCenter,
              centerY: yCenter,
              rot,
              halfThickness: thickness / 2
            });
            topMat.side = THREE.DoubleSide;
            mesh = new THREE.Mesh(boxGeo, topMat);
            mesh.position.set(xWorld, isWaterfall ? -(splashH / 2) : (thickness + 0.06 + splashH / 2), zWorld);
            mesh.rotation.y = (rot * Math.PI) / 180;
          } else {
            const {shape} = geomToOuterPath(g);
            addCutoutHoles(shape, p.cutouts);

            const bevel = true;
            const bevelSize = 0.12;
            const extrude = new THREE.ExtrudeGeometry(shape, {
              depth: thickness,
              bevelEnabled: bevel,
              bevelThickness: 0.08,
              bevelSize,
              bevelOffset: 0,
              bevelSegments: 2,
              curveSegments: 24,
            });
            applySlabUVs(extrude, {
              slabW,
              slabH,
              centerX: xCenter,
              centerY: yCenter,
              rot
            });
            extrude.computeVertexNormals();

            mesh = new THREE.Mesh(extrude, [topMat, sideMat]);
            mesh.rotation.x = -Math.PI / 2;
            mesh.rotation.z = (rot * Math.PI) / 180;
            mesh.position.set(xWorld, 0.02, zWorld);
            mesh.position.y = 0.06;
          }

          scene.add(mesh);
          modelObjects.push(mesh);

          const seams = Array.isArray(p.seams) ? p.seams : [];
          if (!isBacksplash && !isWaterfall && seams.length) {
            seams.forEach((s) => {
              const x1 = Number(s.x1) || 0;
              const y1 = Number(s.y1) || 0;
              const x2 = Number(s.x2) || 0;
              const y2 = Number(s.y2) || 0;
              const pts = [
                new THREE.Vector3(x1, y1, thickness + 0.03),
                new THREE.Vector3(x2, y2, thickness + 0.03),
              ];
              const geo = new THREE.BufferGeometry().setFromPoints(pts);
              const line = new THREE.Line(geo, new THREE.LineDashedMaterial({color: 0xf59e0b, dashSize: 4, gapSize: 3}));
              line.computeLineDistances();
              line.rotation.x = -Math.PI / 2;
              line.rotation.z = (rot * Math.PI) / 180;
              line.position.copy(mesh.position);
              scene.add(line);
            });
          }

          const splashRuns = Array.isArray(p.splashRuns) ? p.splashRuns : [];
          if (!isBacksplash && splashRuns.length) {
            const baseW = Number(g.w) || 0;
            const baseH = Number(g.h) || 0;
            const backEdgeZ = (baseH / 2) + 0.4;
            let cursorX = -(baseW / 2);
            splashRuns.forEach((pair) => {
              const L = Number(pair?.[0]) || 0;
              const Hh = Number(pair?.[1]) || 0;
              if (L <= 0 || Hh <= 0) return;
              const sShape = new THREE.Shape();
              sShape.moveTo(0, 0);
              sShape.lineTo(L, 0);
              sShape.lineTo(L, Hh);
              sShape.lineTo(0, Hh);
              sShape.closePath();
              const sGeo = new THREE.ExtrudeGeometry(sShape, {depth: thickness, bevelEnabled:false});
              const sMesh = new THREE.Mesh(sGeo, new THREE.MeshStandardMaterial({color: 0xa7f3d0, roughness:0.6, metalness:0.0}));
              // orient: panel vertical (Y up), thickness along Z.
              sMesh.rotation.y = Math.PI; // face forward
              sMesh.position.set(cursorX, thickness + 0.06, -backEdgeZ);
              cursorX += L + 2;

              // Attach to countertop local space
              const grp = new THREE.Group();
              grp.add(sMesh);
              grp.rotation.x = -Math.PI / 2;
              grp.rotation.z = (rot * Math.PI) / 180;
              grp.position.copy(mesh.position);
              scene.add(grp);
              modelObjects.push(grp);
            });
          }

          allTargets.push(mesh.position);
        });
    }

    stoneRow += 1;
  }

  }

  function resize(){
    const r = canvas.getBoundingClientRect();
    const w = Math.max(1, Math.floor(r.width));
    const h = Math.max(1, Math.floor(r.height));
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }

  resize();
  window.addEventListener('resize', resize);

  // Auto-frame the actual countertop model, not the full floor grid/slab scene.
  const box = new THREE.Box3();
  if (modelObjects.length) {
    modelObjects.forEach((obj) => box.expandByObject(obj));
  } else {
    box.setFromObject(scene);
  }
  const size = new THREE.Vector3();
  box.getSize(size);
  const center = new THREE.Vector3();
  box.getCenter(center);
  controls.target.copy(center);
  const maxDim = Math.max(size.x, size.y, size.z, 1);
  const fov = camera.fov * (Math.PI / 180);
  // Open a little closer than the conservative full-scene fit, while still leaving room to inspect edges.
  const visibleFraction = 0.6;
  const fitDist = (maxDim / visibleFraction) / (2 * Math.tan(fov / 2));
  const distance = Math.max(55, fitDist * 0.74);
  camera.position.set(center.x + distance * 0.78, center.y + distance * 0.58, center.z + distance * 0.78);
  controls.minDistance = Math.max(5, distance * 0.08);
  controls.maxDistance = Math.max(500, distance * 8);
  controls.update();
  controls.saveState();

  let running = true;
  function tick(){
    if (!running) return;
    controls.update();
    renderer.render(scene, camera);
    requestAnimationFrame(tick);
  }
  tick();

  return {
    renderer,
    scene,
    camera,
    controls,
    grid,
    stop: () => {
      running = false;
      window.removeEventListener('keydown', onSpacePanKeyDown, true);
      window.removeEventListener('keyup', onSpacePanKeyUp, true);
      window.removeEventListener('blur', onSpacePanBlur);
      setSpacePanMode(false);
    },
  };
}

function wireTabs(){
  const tabs = Array.from(document.querySelectorAll('.tab[data-tab]'));
  const pane3d = $('#pane-3d');
  const pane2d = $('#pane-2d');
  tabs.forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.tab;
    tabs.forEach((x) => x.classList.toggle('active', x === b));
    pane3d.classList.toggle('active', t === '3d');
    pane2d.classList.toggle('active', t === '2d');
  }));
}

function selectViewerTab(tabName){
  const btn = document.querySelector(`.tab[data-tab="${tabName}"]`);
  if (btn) btn.click();
}

function show3DMessage(message){
  const wrap = $('#threeWrap');
  if (!wrap) return;
  let el = wrap.querySelector('#ogm-3d-error');
  if (!el) {
    el = document.createElement('div');
    el.id = 'ogm-3d-error';
    el.style.position = 'absolute';
    el.style.inset = '12px';
    el.style.display = 'flex';
    el.style.alignItems = 'center';
    el.style.justifyContent = 'center';
    el.style.textAlign = 'center';
    el.style.padding = '14px 16px';
    el.style.borderRadius = '12px';
    el.style.border = '1px solid rgba(148,163,184,.25)';
    el.style.background = 'rgba(2,6,23,.72)';
    el.style.color = 'var(--txt)';
    el.style.fontSize = '12px';
    el.style.zIndex = '5';
    wrap.appendChild(el);
  }
  el.textContent = String(message || '3D viewer unavailable.');
}

function set3DEnabled(enabled, reason){
  const tab3d = document.querySelector('.tab[data-tab="3d"]');
  if (tab3d) {
    tab3d.disabled = !enabled;
    tab3d.style.opacity = enabled ? '1' : '0.55';
    tab3d.title = enabled ? '' : (reason ? `3D disabled: ${reason}` : '3D disabled');
  }
  if (!enabled) {
    const tab2d = document.querySelector('.tab[data-tab="2d"]');
    if (tab2d) tab2d.click();
  }
}

function wireActions(view){
  let isLight = viewerLightTheme;
  const btnTheme = $('#btn-theme');
  const btnReset = $('#btn-reset');
  const btnShot = $('#btn-shot');
  const btnFull = $('#btn-full');
  const wrap = $('#threeWrap');
  let btnGrid = $('#btn-grid');
  let btnRoomSplit = $('#btn-room-split');
  let btnDimensions = $('#btn-dimensions');
  let btnDimReset = $('#btn-dim-reset');

  if (!btnGrid && btnTheme && btnTheme.parentElement) {
    btnGrid = document.createElement('button');
    btnGrid.className = 'btn secondary';
    btnGrid.type = 'button';
    btnGrid.id = 'btn-grid';
    btnTheme.insertAdjacentElement('afterend', btnGrid);
  }
  if (!btnRoomSplit && btnReset && btnReset.parentElement) {
    btnRoomSplit = document.createElement('button');
    btnRoomSplit.className = 'btn secondary';
    btnRoomSplit.type = 'button';
    btnRoomSplit.id = 'btn-room-split';
    btnReset.insertAdjacentElement('beforebegin', btnRoomSplit);
  }
  if (!btnDimensions && btnReset && btnReset.parentElement) {
    btnDimensions = document.createElement('button');
    btnDimensions.className = 'btn secondary';
    btnDimensions.type = 'button';
    btnDimensions.id = 'btn-dimensions';
    btnReset.insertAdjacentElement('beforebegin', btnDimensions);
  }
  if (!btnDimReset && btnDimensions && btnDimensions.parentElement) {
    btnDimReset = document.createElement('button');
    btnDimReset.className = 'btn secondary';
    btnDimReset.type = 'button';
    btnDimReset.id = 'btn-dim-reset';
    btnDimReset.textContent = 'Reset Dims';
    btnDimensions.insertAdjacentElement('afterend', btnDimReset);
  }

  function syncGridButton(){
    if (!btnGrid) return;
    const on = !!(view?.grid && view.grid.visible);
    btnGrid.textContent = on ? 'Grid: On' : 'Grid: Off';
    btnGrid.title = on ? 'Hide 3D floor grid' : 'Show 3D floor grid';
    btnGrid.disabled = !view?.grid;
    btnGrid.style.borderColor = on ? 'rgba(245,158,11,.72)' : '';
    btnGrid.style.boxShadow = on ? '0 0 0 2px rgba(245,158,11,.15) inset' : '';
    btnGrid.style.opacity = view?.grid ? '1' : '0.55';
  }
  syncGridButton();

  function syncRoomSplitButton(){
    if (!btnRoomSplit) return;
    if (VIEWER_ROOM_KEY) {
      btnRoomSplit.style.display = 'none';
      return;
    }
    btnRoomSplit.textContent = viewerSeparateRooms ? 'Rooms: Split' : 'Rooms: Together';
    btnRoomSplit.title = viewerSeparateRooms ? 'Switch to combined assembly view' : 'Separate 2D/3D by room';
    btnRoomSplit.style.borderColor = viewerSeparateRooms ? 'rgba(245,158,11,.72)' : '';
    btnRoomSplit.style.boxShadow = viewerSeparateRooms ? '0 0 0 2px rgba(245,158,11,.15) inset' : '';
  }
  syncRoomSplitButton();

  function rebuild3DView(){
    if (!SNAPSHOT || !THREE || !OrbitControls) return;
    const gridWasVisible = !!(view?.grid && view.grid.visible);
    try {
      if (view?.stop) view.stop();
      if (view?.renderer?.dispose) view.renderer.dispose();
    } catch (_) {}
    try {
      view = build3D(viewerActiveSnapshot());
      if (view?.grid) view.grid.visible = gridWasVisible;
      if (view?.renderer) view.renderer.setClearColor(isLight ? 0xf8fafc : 0x0b1222, 1);
      syncGridButton();
    } catch (err) {
      const msg = (err && err.message) ? err.message : String(err || '');
      set3DEnabled(false, msg || '3D renderer failed to rebuild.');
      show3DMessage(`3D renderer failed to rebuild. ${msg}`.trim());
    }
  }

  if (btnGrid) btnGrid.addEventListener('click', () => {
    if (!view?.grid) return;
    view.grid.visible = !view.grid.visible;
    syncGridButton();
  });
  if (btnRoomSplit) btnRoomSplit.addEventListener('click', () => {
    viewerSeparateRooms = !viewerSeparateRooms;
    syncRoomSplitButton();
    rerender2DViewer();
    rebuild3DView();
  });
  if (btnDimensions) btnDimensions.addEventListener('click', () => {
    viewer2DDimensionsOn = !viewer2DDimensionsOn;
    rerender2DViewer();
    sync2DControls();
  });
  if (btnDimReset) btnDimReset.addEventListener('click', () => {
    viewer2DHiddenDimensionKeys.clear();
    viewer2DDimensionsOn = true;
    rerender2DViewer();
    sync2DControls();
  });
  sync2DControls();
  syncRoomSplitButton();

  if (btnTheme) btnTheme.addEventListener('click', () => {
    isLight = !isLight;
    setTheme(isLight);
    if (view?.renderer) {
      view.renderer.setClearColor(isLight ? 0xf8fafc : 0x0b1222, 1);
    }
  });

  if (btnReset) btnReset.addEventListener('click', () => {
    if (!view?.controls) return;
    view.controls.reset();
  });

  if (btnShot) btnShot.addEventListener('click', () => {
    const canvas = $('#three');
    if (!canvas) return;
    try {
      const url = canvas.toDataURL('image/png');
      const w = window.open('about:blank', '_blank');
      if (w) w.document.write(`<title>Snapshot</title><img src="${url}" style="max-width:100%"/>`);
    } catch {
      alert('Snapshot failed in this browser.');
    }
  });

  if (btnFull) btnFull.addEventListener('click', async () => {
    if (!wrap) return;
    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen();
      } else {
        await wrap.requestFullscreen();
      }
    } catch {
      // ignore
    }
  });
}

async function main(){
  if (!SNAPSHOT) {
    show3DMessage('Missing viewer snapshot.');
    return;
  }

  wireTabs();
  if (VIEWER_INITIAL_VIEW === '2d' || VIEWER_INITIAL_VIEW === '3d') {
    selectViewerTab(VIEWER_INITIAL_VIEW);
  }
  render2D(viewerActiveSnapshot());
  notifyViewerReady('2d');
  setTheme(INITIAL_LIGHT_THEME);

  if (VIEWER_EMBEDDED && VIEWER_INITIAL_VIEW === '2d') {
    wireActions(null);
    return;
  }

  const loaded = await loadThree();
  if (!loaded.ok) {
    set3DEnabled(false, loaded.error || '3D engine not available.');
    show3DMessage(loaded.error || '3D viewer unavailable.');
    wireActions(null);
    return;
  }

  let view = null;
  try {
    view = build3D(viewerActiveSnapshot());
    if (view?.renderer) view.renderer.setClearColor(INITIAL_LIGHT_THEME ? 0xf8fafc : 0x0b1222, 1);
    notifyViewerReady('3d');
  } catch (err) {
    const msg = (err && err.message) ? err.message : String(err || '');
    set3DEnabled(false, msg || '3D renderer failed to start.');
    show3DMessage(`3D renderer failed to start. ${msg}`.trim());
    view = null;
  }
  wireActions(view);
}

main();
