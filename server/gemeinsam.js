/* Gemeinsame Funktionen für die Mitarbeiterinnen-Seite (index.html) und den Admin-Bereich (admin.html) */

const TYPEN = [
  { id: "arbeit", label: "Arbeit",    farbe: "var(--arbeit)" },
  { id: "ferien", label: "Ferien",    farbe: "var(--ferien)" },
  { id: "krank",  label: "Krankheit", farbe: "var(--krank)" },
  { id: "unfall", label: "Unfall",    farbe: "var(--unfall)" },
  { id: "andere", label: "Andere",    farbe: "var(--andere)" },
];
const TYP = Object.fromEntries(TYPEN.map(t => [t.id, t]));
const WT = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
const WT_LANG = ["Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag"];
const MONATE = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"];
const $ = id => document.getElementById(id);

function esc(s) { return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }
function iso(j, m, t) { return `${j}-${String(m + 1).padStart(2, "0")}-${String(t).padStart(2, "0")}`; }
function tageImMonat(j, m) { return new Date(j, m + 1, 0).getDate(); }
function zahl(x) { return Math.round(x * 100) / 100; }
function fmt(x) { return zahl(x).toLocaleString("de-CH"); }

/* --- Verbindung zum Server --- */
async function api(aktion, daten = {}) {
  let r;
  try {
    r = await fetch("api.php?a=" + encodeURIComponent(aktion), {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json" }, body: JSON.stringify(daten),
    });
  } catch { throw Object.assign(new Error("Keine Verbindung zum Server. Bitte Internet prüfen."), { status: 0 }); }
  let j = {};
  try { j = await r.json(); } catch {}
  if (!r.ok) throw Object.assign(new Error(j.fehler || `Serverfehler (${r.status})`), { status: r.status });
  return j;
}

/* --- Bankfeiertage Liechtenstein --- */
function ostern(j) {  // Gauss'sche Osterformel (gregorianisch)
  const a = j % 19, b = Math.floor(j / 100), c = j % 100, d = Math.floor(b / 4), e = b % 4;
  const f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3), h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
  const mon = Math.floor((h + l - 7 * m + 114) / 31), tag = ((h + l - 7 * m + 114) % 31) + 1;
  return new Date(j, mon - 1, tag);
}
const feiertagCache = {};
function feiertage(j) {
  if (feiertagCache[j]) return feiertagCache[j];
  const f = {}, o = ostern(j);
  const fest = (m, t, name) => f[iso(j, m - 1, t)] = name;
  const beweglich = (tage, name) => { const d = new Date(o); d.setDate(d.getDate() + tage); f[iso(d.getFullYear(), d.getMonth(), d.getDate())] = name; };
  fest(1, 1, "Neujahr"); fest(1, 2, "Berchtoldstag"); fest(1, 6, "Heilige Drei Könige"); fest(2, 2, "Mariä Lichtmess");
  beweglich(-47, "Fasnachtsdienstag"); fest(3, 19, "Josefstag");
  beweglich(-2, "Karfreitag"); beweglich(0, "Ostersonntag"); beweglich(1, "Ostermontag"); fest(5, 1, "Tag der Arbeit");
  beweglich(39, "Auffahrt"); beweglich(49, "Pfingstsonntag"); beweglich(50, "Pfingstmontag"); beweglich(60, "Fronleichnam");
  fest(8, 15, "Staatsfeiertag"); fest(9, 8, "Mariä Geburt"); fest(11, 1, "Allerheiligen"); fest(12, 8, "Mariä Empfängnis");
  fest(12, 24, "Heiligabend"); fest(12, 25, "Weihnachten"); fest(12, 26, "Stephanstag"); fest(12, 31, "Silvester");
  return feiertagCache[j] = f;
}
function feiertag(k) { return feiertage(Number(k.slice(0, 4)))[k] || ""; }
function feiertageImMonat(j, m) {
  const liste = [];
  for (let t = 1; t <= tageImMonat(j, m); t++) {
    const k = iso(j, m, t), wt = new Date(j, m, t).getDay();
    if (feiertag(k) && wt !== 0 && wt !== 6) liste.push(k);
  }
  return liste;
}

/* --- Auswertung --- */
function beschreibung(e) {
  if (e.typ === "arbeit") return `${fmt(e.std)} Std.`;
  if (e.tage === 1) return "Ganzer Tag";
  if (e.tage === 0.5) return "Halber Tag";
  return `${fmt(e.std)} Std.`;
}
function summen(liste) {
  const s = Object.fromEntries(TYPEN.map(t => [t.id, { tage: 0, std: 0 }]));
  const arbeitstage = new Set();
  for (const e of liste) {
    s[e.typ].std += e.std || 0;
    s[e.typ].tage += e.tage || 0;
    if (e.typ === "arbeit") arbeitstage.add(e.datum);
  }
  s.arbeit.tage = arbeitstage.size;
  for (const t of TYPEN) { s[t.id].std = zahl(s[t.id].std); s[t.id].tage = zahl(s[t.id].tage); }
  return s;
}
function abwText(x) {
  return x.tage ? fmt(x.tage) + " T" + (x.std ? " + " + fmt(x.std) + " h" : "") : (x.std ? fmt(x.std) + " h" : "–");
}

/* --- Excel --- */
const KOPF = { type: "pattern", pattern: "solid", fgColor: { argb: "FF0F766E" } };
const fuellung = argb => ({ type: "pattern", pattern: "solid", fgColor: { argb } });
function kopfzeile(row) {
  row.eachCell(c => { c.font = { bold: true, color: { argb: "FFFFFFFF" } }; c.fill = KOPF; c.alignment = { wrapText: true, vertical: "middle" }; });
}

// Ein Blatt mit jedem Tag des Monats und dem, was eingetragen wurde
function monatsBlatt(wb, blattName, firma, nr, jahr, monat, liste) {
  const titel = `${MONATE[monat]} ${jahr}`;
  const d = wb.addWorksheet(blattName);
  d.columns = [{ width: 11 }, { width: 6 }, { width: 18 }, { width: 10 }, { width: 9 }, { width: 10 },
    { width: 9 }, { width: 9 }, { width: 11 }, { width: 34 }, { width: 34 }];
  d.addRow([`${firma} – Stundenrapport ${titel}`]).font = { bold: true, size: 14 };
  d.addRow(["Personalnummer:", "", nr]).font = { bold: true };
  d.addRow(["Erstellt am:", "", new Date().toLocaleString("de-CH")]);
  d.addRow([]);
  const h = d.addRow(["Datum", "Tag", "Feiertag", "Arbeit (Std.)", "Ferien (Tage)", "Krankheit (Tage)",
    "Unfall (Tage)", "Andere (Tage)", "Abwesend (Std.)", "Eingetragen", "Bemerkung"]);
  h.height = 30; kopfzeile(h);
  const erste = h.number + 1;
  const proTag = {};
  for (const e of liste) (proTag[e.datum] ||= []).push(e);
  for (let t = 1; t <= tageImMonat(jahr, monat); t++) {
    const k = iso(jahr, monat, t), wt = new Date(jahr, monat, t).getDay();
    const es = proTag[k] || [];
    const sum = (typ, feld) => zahl(es.filter(e => e.typ === typ).reduce((a, e) => a + (e[feld] || 0), 0)) || null;
    const abwStd = zahl(es.filter(e => e.typ !== "arbeit").reduce((a, e) => a + (e.std || 0), 0)) || null;
    const r = d.addRow([new Date(Date.UTC(jahr, monat, t)), WT[wt], feiertag(k),
      sum("arbeit", "std"), sum("ferien", "tage"), sum("krank", "tage"), sum("unfall", "tage"), sum("andere", "tage"), abwStd,
      es.map(e => `${TYP[e.typ].label}: ${beschreibung(e)}`).join(", "),
      es.filter(e => e.notiz).map(e => e.notiz).join("; ")]);
    if (feiertag(k)) r.eachCell({ includeEmpty: true }, c => c.fill = fuellung("FFEFE9FB"));
    else if (wt === 0 || wt === 6) r.eachCell({ includeEmpty: true }, c => c.fill = fuellung("FFF1F3F2"));
  }
  const letzte = d.lastRow.number;
  const spaltenSumme = col => { let x = 0; for (let r = erste; r <= letzte; r++) x += d.getRow(r).getCell(col).value || 0; return zahl(x); };
  const total = d.addRow(["Total", "", "", ...["D", "E", "F", "G", "H", "I"].map((c, i) =>
    ({ formula: `SUM(${c}${erste}:${c}${letzte})`, result: spaltenSumme(i + 4) })), "", ""]);
  total.font = { bold: true };
  total.eachCell({ includeEmpty: true }, c => c.border = { top: { style: "medium" } });
  d.getColumn(1).numFmt = "dd.mm.yyyy";
  for (let c = 4; c <= 9; c++) d.getColumn(c).numFmt = "0.##";
  [10, 11].forEach(c => d.getColumn(c).alignment = { wrapText: true, vertical: "top" });
  d.views = [{ state: "frozen", ySplit: erste - 1 }];
  d.pageSetup = { orientation: "landscape", fitToPage: true, fitToWidth: 1, fitToHeight: 0 };
  return d;
}

// Übersicht: eine Zeile pro Personalnummer
function uebersichtsBlatt(wb, firma, jahr, monat, personen) {
  const u = wb.addWorksheet("Übersicht");
  u.columns = [{ width: 16 }, { width: 12 }, { width: 11 }, { width: 11 }, { width: 13 }, { width: 11 }, { width: 11 }, { width: 13 }];
  u.addRow([`${firma} – Übersicht ${MONATE[monat]} ${jahr}`]).font = { bold: true, size: 14 };
  u.addRow([`Feiertage Mo–Fr: ${feiertageImMonat(jahr, monat).length}`]);
  u.addRow([]);
  const h = u.addRow(["Personalnummer", "Arbeit (Std.)", "Arbeitstage", "Ferien (Tage)", "Krankheit (Tage)", "Unfall (Tage)", "Andere (Tage)", "Abwesend (Std.)"]);
  h.height = 30; kopfzeile(h);
  for (const { nr, liste } of personen) {
    const s = summen(liste);
    u.addRow([nr, s.arbeit.std || null, s.arbeit.tage || null, s.ferien.tage || null, s.krank.tage || null, s.unfall.tage || null,
      s.andere.tage || null, zahl(s.ferien.std + s.krank.std + s.unfall.std + s.andere.std) || null]);
  }
  for (let c = 2; c <= 8; c++) u.getColumn(c).numFmt = "0.##";
  return u;
}

function excelDateiname(jahr, monat, teil) {
  return `Stunden_${jahr}-${String(monat + 1).padStart(2, "0")}_${teil}.xlsx`;
}
async function alsDatei(wb, name) {
  const buf = await wb.xlsx.writeBuffer();
  return new File([buf], name, { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
}
function herunterladen(datei) {
  const a = document.createElement("a");
  a.href = URL.createObjectURL(datei); a.download = datei.name; a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}
