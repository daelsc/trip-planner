const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fm = require('../flight-math.js');

describe('haversine', () => {
  it('KSJC→KLAX ~295nm', () => {
    // KSJC: 37.3626, -121.929; KLAX: 33.9425, -118.408
    const d = fm.haversine(37.3626, -121.929, 33.9425, -118.408);
    assert.ok(d > 280 && d < 310, `expected ~295nm, got ${d}`);
  });

  it('KJFK→EGLL ~3000nm', () => {
    // KJFK: 40.6398, -73.7789; EGLL: 51.4706, -0.4619
    const d = fm.haversine(40.6398, -73.7789, 51.4706, -0.4619);
    assert.ok(d > 2950 && d < 3050, `expected ~3000nm, got ${d}`);
  });

  it('same airport = 0', () => {
    const d = fm.haversine(37.3626, -121.929, 37.3626, -121.929);
    assert.ok(d < 0.01, `expected 0, got ${d}`);
  });

  it('antipodal ~10800nm', () => {
    const d = fm.haversine(0, 0, 0, 180);
    assert.ok(d > 10790 && d < 10810, `expected ~10800nm, got ${d}`);
  });
});

describe('bearing', () => {
  it('KSJC→KLAX ~135°', () => {
    const b = fm.bearing(37.3626, -121.929, 33.9425, -118.408);
    assert.ok(b > 125 && b < 145, `expected ~135°, got ${b}`);
  });

  it('KJFK→EGLL ~51°', () => {
    const b = fm.bearing(40.6398, -73.7789, 51.4706, -0.4619);
    assert.ok(b > 45 && b < 60, `expected ~51°, got ${b}`);
  });
});

describe('calcBlockMin', () => {
  const g550 = fm.PROFILES.G550;

  it('G550 295nm no wind ~57min', () => {
    const bm = fm.calcBlockMin(295, g550, 0);
    assert.ok(bm > 50 && bm < 65, `expected ~57min, got ${bm}`);
  });

  it('50kt headwind → longer block', () => {
    const noWind = fm.calcBlockMin(295, g550, 0);
    const hw = fm.calcBlockMin(295, g550, 50);
    assert.ok(hw > noWind, `headwind ${hw} should be > no-wind ${noWind}`);
  });

  it('50kt tailwind → shorter block', () => {
    const noWind = fm.calcBlockMin(295, g550, 0);
    const tw = fm.calcBlockMin(295, g550, -50);
    assert.ok(tw < noWind, `tailwind ${tw} should be < no-wind ${noWind}`);
  });

  it('short leg uses avg speed path', () => {
    // 20nm is well under climb+descent distance
    const bm = fm.calcBlockMin(20, g550, 0);
    assert.ok(bm != null && bm > 30 && bm < 45, `expected short leg block, got ${bm}`);
  });

  it('extreme headwind caps GS at 50kt', () => {
    const bm = fm.calcBlockMin(295, g550, 500);
    assert.ok(bm != null && bm > 300, `expected very long block with extreme hw, got ${bm}`);
  });

  it('custom profile (no climb/descent)', () => {
    const custom = fm.PROFILES.custom;
    const bm = fm.calcBlockMin(250, custom, 0);
    // 250nm / 250kts = 60min + 10 startup + 5 shutdown = 75min
    assert.ok(bm > 73 && bm < 77, `expected ~75min, got ${bm}`);
  });

  it('zero distance = startup+shutdown', () => {
    const bm = fm.calcBlockMin(0, g550, 0);
    assert.equal(bm, g550.startup + g550.shutdown);
  });

  it('null distance = null', () => {
    assert.equal(fm.calcBlockMin(null, g550, 0), null);
  });
});

describe('calcFlightMin', () => {
  it('returns flight time without startup/shutdown', () => {
    const g550 = fm.PROFILES.G550;
    const flight = fm.calcFlightMin(295, g550, 0);
    const block = fm.calcBlockMin(295, g550, 0);
    assert.ok(Math.abs(block - flight - g550.startup - g550.shutdown) < 0.001);
  });
});

describe('normalizeTime', () => {
  it('"1430" → "14:30"', () => assert.equal(fm.normalizeTime('1430'), '14:30'));
  it('"930" → "09:30"', () => assert.equal(fm.normalizeTime('930'), '09:30'));
  it('"25:00" → ""', () => assert.equal(fm.normalizeTime('25:00'), ''));
  it('"abc" → ""', () => assert.equal(fm.normalizeTime('abc'), ''));
  it('"0:00" → "00:00"', () => assert.equal(fm.normalizeTime('0:00'), '00:00'));
  it('"23:59" → "23:59"', () => assert.equal(fm.normalizeTime('23:59'), '23:59'));
});

describe('fmtDur', () => {
  it('90 → "1:30"', () => assert.equal(fm.fmtDur(90), '1:30'));
  it('0 → "0:00"', () => assert.equal(fm.fmtDur(0), '0:00'));
  it('null → "—"', () => assert.equal(fm.fmtDur(null), '\u2014'));
  it('negative → "—"', () => assert.equal(fm.fmtDur(-5), '\u2014'));
  it('61.4 → "1:01"', () => assert.equal(fm.fmtDur(61.4), '1:01'));
});

describe('esc', () => {
  it('escapes <script>', () => {
    assert.equal(fm.esc('<script>'), '&lt;script&gt;');
  });
  it('null → ""', () => assert.equal(fm.esc(null), ''));
  it('escapes & and "', () => {
    assert.equal(fm.esc('a&b"c'), 'a&amp;b&quot;c');
  });
});

describe('num/numOr/numNull', () => {
  it('num parses int', () => assert.equal(fm.num('42'), 42));
  it('num returns 0 for garbage', () => assert.equal(fm.num('abc'), 0));
  it('numOr returns default', () => assert.equal(fm.numOr('abc', 7), 7));
  it('numOr returns parsed', () => assert.equal(fm.numOr('10', 7), 10));
  it('numNull returns null', () => assert.equal(fm.numNull('abc'), null));
  it('numNull returns parsed', () => assert.equal(fm.numNull('5'), 5));
});

describe('dateInTz', () => {
  it('UTC offset is 0', () => {
    const d = fm.dateInTz('2026-03-20T12:00', 'UTC');
    assert.equal(d.getUTCHours(), 12);
    assert.equal(d.getUTCMinutes(), 0);
  });

  it('America/New_York is UTC-4 or UTC-5', () => {
    // March 20 2026 is during EDT (UTC-4)
    const d = fm.dateInTz('2026-03-20T12:00', 'America/New_York');
    assert.equal(d.getUTCHours(), 16); // 12:00 EDT = 16:00 UTC
  });

  it('America/Los_Angeles is UTC-7 in March (PDT)', () => {
    const d = fm.dateInTz('2026-03-20T08:00', 'America/Los_Angeles');
    assert.equal(d.getUTCHours(), 15); // 08:00 PDT = 15:00 UTC
  });
});

describe('timeToDate', () => {
  it('time after ref → same day', () => {
    const ref = fm.dateInTz('2026-03-20T08:00', 'UTC');
    const result = fm.timeToDate('10:00', ref, 'UTC');
    assert.equal(result.getUTCHours(), 10);
    assert.equal(result.getUTCDate(), 20);
  });

  it('time before ref → next day', () => {
    const ref = fm.dateInTz('2026-03-20T22:00', 'UTC');
    const result = fm.timeToDate('06:00', ref, 'UTC');
    assert.equal(result.getUTCHours(), 6);
    assert.equal(result.getUTCDate(), 21);
  });
});

describe('fmtDate', () => {
  it('formats date with weekday', () => {
    const d = new Date(Date.UTC(2026, 2, 20, 12, 0)); // March 20
    const s = fm.fmtDate(d, 'UTC');
    assert.ok(s.includes('Mar'), `expected Mar in "${s}"`);
    assert.ok(s.includes('20'), `expected 20 in "${s}"`);
  });
});
