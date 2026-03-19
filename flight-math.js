// flight-math.js — shared pure functions for trip planner
// Works as both a <script> tag (browser) and require() (Node.js)
(function(root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else {
    var fm = factory();
    for (var k in fm) root[k] = fm[k];
  }
}(typeof globalThis !== 'undefined' ? globalThis : this, function() {

  var PROFILES = {
    G550:   { speed: 478, climbSpeed: 325, descentSpeed: 325, phaseMin: 15, startup: 20, shutdown: 10 },
    PC12:   { speed: 270, climbSpeed: 180, descentSpeed: 180, phaseMin: 15, startup: 10, shutdown: 5 },
    custom: { speed: 250, startup: 10, shutdown: 5 },
  };

  var R_NM = 3440.065;
  function toRad(d) { return d * Math.PI / 180; }
  function toDeg(r) { return r * 180 / Math.PI; }

  function haversine(lat1, lon1, lat2, lon2) {
    var dLat = toRad(lat2-lat1), dLon = toRad(lon2-lon1);
    var a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
    return 2 * R_NM * Math.asin(Math.sqrt(a));
  }

  function bearing(lat1, lon1, lat2, lon2) {
    var dLon = toRad(lon2-lon1), la1 = toRad(lat1), la2 = toRad(lat2);
    var y = Math.sin(dLon)*Math.cos(la2);
    var x = Math.cos(la1)*Math.sin(la2) - Math.sin(la1)*Math.cos(la2)*Math.cos(dLon);
    return (toDeg(Math.atan2(y, x)) + 360) % 360;
  }

  function normalizeTime(v) {
    v = v.replace(/[^0-9:]/g, '');
    if (/^\d{3,4}$/.test(v)) v = v.slice(0,-2) + ':' + v.slice(-2);
    var m = v.match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return '';
    var h = parseInt(m[1],10), mn = parseInt(m[2],10);
    if (h > 23 || mn > 59) return '';
    return h.toString().padStart(2,'0') + ':' + mn.toString().padStart(2,'0');
  }

  function fmtDur(min) {
    if (min == null || min < 0) return '\u2014';
    var total = Math.round(min);
    var h = Math.floor(total/60), m = total % 60;
    return h + ':' + m.toString().padStart(2,'0');
  }

  function num(v) { var n = parseInt(v,10); return isNaN(n) ? 0 : n; }
  function numOr(v, d) { var n = parseInt(v,10); return isNaN(n) ? d : n; }
  function numNull(v) { var n = parseInt(v,10); return isNaN(n) ? null : n; }

  function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function fmtInTz(date, tz) {
    var time = date.toLocaleString('en-US', { timeZone: tz, hour:'2-digit', minute:'2-digit', hour12: false });
    var abbr;
    try { abbr = date.toLocaleString('en-US', { timeZone: tz, timeZoneName:'short' }).split(' ').pop(); }
    catch(e) { abbr = tz.split('/').pop(); }
    return { time: time, abbr: abbr };
  }

  function fmtDate(date, tz) {
    return date.toLocaleString('en-US', { timeZone: tz, weekday:'short', month:'short', day:'numeric' });
  }

  function tzParts(date, tz) {
    var f = new Intl.DateTimeFormat('en-US', {
      timeZone:tz, year:'numeric', month:'numeric', day:'numeric',
      hour:'numeric', minute:'numeric', hour12:false
    });
    var o = {};
    var parts = f.formatToParts(date);
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      if (p.type==='day') o.d = +p.value;
      if (p.type==='hour') o.h = (+p.value) % 24;
      if (p.type==='minute') o.m = +p.value;
    }
    return o;
  }

  function dateInTz(dtStr, tz) {
    var dp = dtStr.split('T')[0], tp = dtStr.split('T')[1];
    var dParts = dp.split('-').map(Number);
    var y = dParts[0], mo = dParts[1], d = dParts[2];
    var tParts = tp.split(':').map(Number);
    var h = tParts[0], m = tParts[1];
    var guess = new Date(Date.UTC(y, mo-1, d, h, m, 0));
    for (var i = 0; i < 3; i++) {
      var p = tzParts(guess, tz);
      var diff = ((h - p.h)*60 + (m - p.m))*60000 + (d - p.d)*86400000;
      guess = new Date(guess.getTime() + diff);
    }
    return guess;
  }

  function timeToDate(timeStr, refDate, tz) {
    var fp = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year:'numeric', month:'2-digit', day:'2-digit' }).format(refDate);
    var dt = dateInTz(fp + 'T' + timeStr, tz);
    while (dt < refDate) dt = new Date(dt.getTime() + 86400000);
    return dt;
  }

  /**
   * Calculate flight time in minutes (airborne only, no startup/shutdown).
   * @param {number|null} distNm - distance in nautical miles (null → null)
   * @param {object} profile - { speed, climbSpeed?, descentSpeed?, phaseMin? }
   * @param {number} [headwind=0] - headwind component in knots (positive = headwind, negative = tailwind)
   * @returns {number|null} flight time in minutes, or null if distance unknown
   */
  function calcFlightMin(distNm, profile, headwind) {
    if (distNm == null || !profile || profile.speed <= 0) return null;
    headwind = headwind || 0;
    var cruiseGs = Math.max(50, profile.speed - headwind);
    if (profile.climbSpeed && profile.descentSpeed && profile.phaseMin) {
      var climbGs = Math.max(50, profile.climbSpeed - headwind);
      var descentGs = Math.max(50, profile.descentSpeed - headwind);
      var climbDist = climbGs * (profile.phaseMin / 60);
      var descentDist = descentGs * (profile.phaseMin / 60);
      if (distNm <= climbDist + descentDist) {
        return (distNm / ((climbGs + descentGs) / 2)) * 60;
      } else {
        var cruiseDist = distNm - climbDist - descentDist;
        return profile.phaseMin + (cruiseDist / cruiseGs) * 60 + profile.phaseMin;
      }
    } else {
      return (distNm / cruiseGs) * 60;
    }
  }

  /**
   * Calculate block time in minutes for a leg (startup + flight + shutdown).
   * @param {number|null} distNm - distance in nautical miles (null → null)
   * @param {object} profile - { speed, climbSpeed?, descentSpeed?, phaseMin?, startup, shutdown }
   * @param {number} [headwind=0] - headwind component in knots (positive = headwind, negative = tailwind)
   * @returns {number|null} block time in minutes, or null if distance unknown
   */
  function calcBlockMin(distNm, profile, headwind) {
    var fm = calcFlightMin(distNm, profile, headwind);
    if (fm == null) return null;
    return (profile.startup || 0) + fm + (profile.shutdown || 0);
  }

  return {
    PROFILES: PROFILES,
    R_NM: R_NM,
    toRad: toRad,
    toDeg: toDeg,
    haversine: haversine,
    bearing: bearing,
    normalizeTime: normalizeTime,
    fmtDur: fmtDur,
    num: num,
    numOr: numOr,
    numNull: numNull,
    esc: esc,
    fmtInTz: fmtInTz,
    fmtDate: fmtDate,
    tzParts: tzParts,
    dateInTz: dateInTz,
    timeToDate: timeToDate,
    calcFlightMin: calcFlightMin,
    calcBlockMin: calcBlockMin,
  };
}));
