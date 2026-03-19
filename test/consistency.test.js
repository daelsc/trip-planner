const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const fm = require('../flight-math.js');

const sampleState = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/sample-state.json'), 'utf8'));

// Minimal airport data for test airports
const testAirports = {
  KSJC: { lat: 37.3626, lon: -121.929, tz: 'America/Los_Angeles' },
  KLAX: { lat: 33.9425, lon: -118.408, tz: 'America/Los_Angeles' },
  KLAS: { lat: 36.0801, lon: -115.152, tz: 'America/Los_Angeles' },
};

function buildProfile(state) {
  const aircraftType = state.a || 'G550';
  const base = fm.PROFILES[aircraftType] || fm.PROFILES.custom;
  return {
    speed: state.os ? +state.os : base.speed,
    climbSpeed: state.oc ? +state.oc : (base.climbSpeed || null),
    descentSpeed: state.od ? +state.od : (base.descentSpeed || null),
    phaseMin: base.phaseMin || null,
    startup: state.osu != null ? +state.osu : base.startup,
    shutdown: state.osd != null ? +state.osd : base.shutdown,
  };
}

// Simulate how pax.html/planner.html compute times
function computeTimes(state, aptIdx) {
  const profile = buildProfile(state);
  const pairs = state.l.split(',');
  const grounds = state.g ? state.g.split(',') : [];
  const storedBlocks = state.bt ? state.bt.split(',') : [];
  const deps = state.d ? state.d.split(',') : [];
  const depDates = state.dd ? state.dd.split(',') : [];

  const legs = pairs.map((pair, i) => {
    const [dep, arr] = pair.split('-');
    return { dep, arr, groundMin: (grounds[i] && grounds[i] !== '') ? +grounds[i] : null, nextDep: deps[i] || '', nextDepDate: depDates[i] || '' };
  });
  for (let i = 1; i < legs.length; i++) {
    if (legs[i-1].arr) legs[i].dep = legs[i-1].arr;
  }

  let currentTime = null;
  if (state.t) {
    const firstTz = aptIdx[legs[0].dep]?.tz || 'UTC';
    currentTime = fm.dateInTz(state.t, firstTz);
  }

  const results = [];
  for (let i = 0; i < legs.length; i++) {
    const leg = legs[i];
    const depApt = aptIdx[leg.dep], arrApt = aptIdx[leg.arr];
    const depTz = depApt?.tz, arrTz = arrApt?.tz;

    let blockMin = (storedBlocks[i] != null && storedBlocks[i] !== '') ? +storedBlocks[i] : null;
    if (blockMin == null) {
      const distNm = (depApt && arrApt) ? fm.haversine(depApt.lat, depApt.lon, arrApt.lat, arrApt.lon) : null;
      blockMin = fm.calcBlockMin(distNm, profile, 0);
    }

    let departTime = null, arriveTime = null;
    if (currentTime && blockMin != null) {
      departTime = new Date(currentTime.getTime());
      arriveTime = new Date(currentTime.getTime() + blockMin * 60000);
    }

    results.push({ blockMin, departTime, arriveTime, depTz, arrTz });

    if (i < legs.length - 1 && arriveTime) {
      const nextDepTz = aptIdx[legs[i+1].dep]?.tz || arrTz;
      const defaultMin = profile.startup + profile.shutdown;
      if (leg.nextDep || leg.nextDepDate) {
        const useDate = leg.nextDepDate || new Intl.DateTimeFormat('en-CA', { timeZone: nextDepTz, year:'numeric', month:'2-digit', day:'2-digit' }).format(arriveTime);
        const useTime = leg.nextDep || new Intl.DateTimeFormat('en-GB', { timeZone: nextDepTz, hour:'2-digit', minute:'2-digit', hour12:false }).format(arriveTime);
        let depOverride = fm.dateInTz(`${useDate}T${useTime}`, nextDepTz);
        while (depOverride < arriveTime) depOverride = new Date(depOverride.getTime() + 86400000);
        currentTime = depOverride;
      } else {
        const gMin = leg.groundMin ?? defaultMin;
        currentTime = new Date(arriveTime.getTime() + gMin * 60000);
      }
    }
  }
  return results;
}

describe('cross-view consistency', () => {
  it('stored bt values match calcBlockMin for known distances', () => {
    const profile = buildProfile(sampleState);
    const pairs = sampleState.l.split(',');
    const storedBlocks = sampleState.bt.split(',');

    for (let i = 0; i < pairs.length; i++) {
      const [dep, arr] = pairs[i].split('-');
      const depApt = testAirports[dep], arrApt = testAirports[arr];
      if (!depApt || !arrApt) continue;
      const distNm = fm.haversine(depApt.lat, depApt.lon, arrApt.lat, arrApt.lon);
      const computed = fm.calcBlockMin(distNm, profile, 0);
      const stored = +storedBlocks[i];
      // Stored values are rounded integers from calcBlockMin; they should be close
      assert.ok(Math.abs(computed - stored) < 2, `leg ${i}: computed ${computed} vs stored ${stored}`);
    }
  });

  it('multi-leg trip: times cascade identically', () => {
    const results = computeTimes(sampleState, testAirports);
    assert.equal(results.length, 2);
    // Both legs should have valid times
    assert.ok(results[0].departTime != null);
    assert.ok(results[0].arriveTime != null);
    assert.ok(results[1].departTime != null);
    assert.ok(results[1].arriveTime != null);
    // Second leg departs after first arrives + ground time
    assert.ok(results[1].departTime > results[0].arriveTime);
  });

  it('with bt set: views use stored values', () => {
    const results = computeTimes(sampleState, testAirports);
    assert.equal(results[0].blockMin, 57);
    assert.equal(results[1].blockMin, 48);
  });

  it('without bt: all views compute same fallback', () => {
    const stateNoBt = { ...sampleState };
    delete stateNoBt.bt;
    const results = computeTimes(stateNoBt, testAirports);
    // Should still produce valid block times via calcBlockMin
    assert.ok(results[0].blockMin != null);
    assert.ok(results[1].blockMin != null);
    assert.ok(results[0].blockMin > 40 && results[0].blockMin < 70);
  });
});

describe('index.html DOM safety', () => {
  // Regression: updateFbStatus() referenced removed DOM elements (#fbStatus, #fbNotifyBtn)
  // which threw a null reference error, breaking recalc() and preventing FBO loading.
  // This test scans index.html for $('id').property patterns without null guards.

  const indexSrc = fs.readFileSync(path.join(__dirname, '../index.html'), 'utf8');

  it('no unguarded $() calls that assume element exists', () => {
    // Match patterns like: $('someId').textContent or $('someId').className
    // These crash if the element doesn't exist. Safe patterns use ?. or check first.
    const lines = indexSrc.split('\n');
    const issues = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      // Match $('...').prop but not $('...')?.prop and not if/? guard on same line
      const m = line.match(/\$\(['"](\w+)['"]\)\./g);
      if (!m) continue;
      for (const call of m) {
        // Skip if it uses optional chaining
        if (line.includes(call.replace(').', ')?.')) continue;
        // Skip if the result is checked for null (e.g. "if (!$('x')) return")
        // Skip the $ = getElementById definition itself
        if (line.includes('function $(id)')) continue;
        // Skip if guarded by if/? on same line or preceding line
        const id = call.match(/\$\(['"](\w+)['"]\)/)[1];
        // Check if this element exists in the HTML
        const elExists = indexSrc.includes(`id="${id}"`) || indexSrc.includes(`id='${id}'`);
        if (!elExists) {
          issues.push({ line: i + 1, id, code: line.trim().substring(0, 80) });
        }
      }
    }
    if (issues.length > 0) {
      const msg = issues.map(i => `  line ${i.line}: $('${i.id}') — element not in HTML\n    ${i.code}`).join('\n');
      assert.fail(`Found $() calls referencing missing DOM elements:\n${msg}`);
    }
  });
});
