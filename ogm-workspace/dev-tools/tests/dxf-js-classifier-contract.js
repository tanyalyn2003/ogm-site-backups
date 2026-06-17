const fs = require('fs');
const path = require('path');
const vm = require('vm');

let root = process.env.OGM_ROOT || path.resolve(__dirname, '..', '..');
if (!fs.existsSync(path.join(root, 'quoter-tool-working', 'ogm-dxf-classify.js'))) {
  root = '/Users/tanyawhite/OGM';
}
const classifierPath = path.join(root, 'quoter-tool-working', 'ogm-dxf-classify.js');
const context = vm.createContext({ console });
vm.runInContext(fs.readFileSync(classifierPath, 'utf8'), context, { filename: classifierPath });

function assertSame(expected, actual, message) {
  if (expected !== actual) {
    throw new Error(`${message} expected=${JSON.stringify(expected)} actual=${JSON.stringify(actual)}`);
  }
}

function lwpoly(points, color = null, layer = '0', closed = true) {
  const parts = ['0', 'LWPOLYLINE', '8', layer];
  if (color !== null) parts.push('62', String(color));
  parts.push('70', closed ? '1' : '0');
  for (const [x, y] of points) parts.push('10', String(x), '20', String(y));
  return parts.join('\n') + '\n';
}

function circle(x, y, r, color = null, layer = '0') {
  const parts = ['0', 'CIRCLE', '8', layer];
  if (color !== null) parts.push('62', String(color));
  parts.push('10', String(x), '20', String(y), '40', String(r));
  return parts.join('\n') + '\n';
}

function text(label, x, y) {
  return ['0', 'TEXT', '8', '0', '10', String(x), '20', String(y), '1', label].join('\n') + '\n';
}

function doc(...entities) {
  return '0\nSECTION\n2\nHEADER\n9\n$INSUNITS\n70\n1\n0\nENDSEC\n'
    + '0\nSECTION\n2\nENTITIES\n'
    + entities.join('')
    + '0\nENDSEC\n0\nEOF\n';
}

function zone(x1, y1, x2, y2) {
  return lwpoly([[x1, y1], [x2, y1], [x2, y2], [x1, y2]], 1);
}

function stone(x1, y1, x2, y2) {
  return lwpoly([[x1, y1], [x2, y1], [x2, y2], [x1, y2]]);
}

function greenRect(x1, y1, x2, y2) {
  return lwpoly([[x1, y1], [x2, y1], [x2, y2], [x1, y2]], 3);
}

function classify(raw) {
  return context.ogmDxfParseAndClassify(raw);
}

let result = classify(doc(stone(0, 0, 100, 25)));
assertSame(1, result.rooms.length, 'black shape fallback room count');
assertSame(1, result.rooms[0].pieces.length, 'black shape imports as stone');
assertSame(0, result.rooms[0].cutouts.length, 'black shape has no cutouts');

result = classify(doc(zone(0, 0, 200, 100), text('Kitchen', 10, 90), stone(10, 10, 110, 35), greenRect(20, 15, 40, 30)));
assertSame('Kitchen', result.rooms[0].label, 'red zone text labels room');
assertSame('rectangle', result.rooms[0].cutouts[0] && result.rooms[0].cutouts[0].shape, 'green polyline imports as rectangle cutout');

result = classify(doc(zone(0, 0, 200, 100), stone(10, 10, 110, 35), circle(50, 22, 8, 3)));
assertSame('circle', result.rooms[0].cutouts[0] && result.rooms[0].cutouts[0].shape, 'green circle imports as round cutout');

result = classify(doc(zone(0, 0, 200, 100), stone(10, 10, 110, 35), circle(50, 22, 8)));
assertSame(0, result.rooms[0].cutouts.length, 'non-green circle is not a cutout');

result = classify(doc(
  zone(0, 0, 100, 100),
  text('Room A', 10, 90),
  stone(10, 10, 80, 35),
  greenRect(20, 15, 30, 25),
  zone(200, 0, 300, 100),
  text('Room B', 210, 90),
  stone(210, 10, 280, 35),
  circle(240, 22, 6, 3)
));
assertSame(2, result.rooms.length, 'multiple red zones create multiple rooms');
assertSame(1, result.rooms[0].cutouts.length, 'zone A cutout assignment');
assertSame(1, result.rooms[1].cutouts.length, 'zone B cutout assignment');

result = classify(doc(stone(0, 0, 100, 25), circle(30, 12, 6, 3)));
assertSame(1, result.rooms.length, 'no-zone fallback room count');
assertSame(1, result.rooms[0].cutouts.length, 'no-zone fallback still honors green cutout');

console.log('Shared JS DXF classifier contract OK');
