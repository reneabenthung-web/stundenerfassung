<?php
/* Stundenerfassung – Schnittstelle zwischen Webseite und Datenbank.
   Alle Anfragen: POST api.php?a=<aktion> mit JSON im Körper, Antwort ist JSON. */
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function antwort(array $daten, int $code = 200): never {
  http_response_code($code);
  echo json_encode($daten, JSON_UNESCAPED_UNICODE);
  exit;
}
function fehler(string $text, int $code = 400): never { antwort(['fehler' => $text], $code); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fehler('Nur POST erlaubt.', 405);
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) fehler('Falscher Inhaltstyp.', 415);
$in = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($in)) fehler('Ungültige Anfrage.');
$aktion = (string)($_GET['a'] ?? '');

/* --- Sitzung (Cookie nur über HTTPS, nicht per JavaScript lesbar, nicht von fremden Seiten) --- */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('stunden');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)(SITZUNG_MINUTEN * 60));
session_start();
if (isset($_SESSION['zuletzt']) && time() - $_SESSION['zuletzt'] > SITZUNG_MINUTEN * 60) { $_SESSION = []; }
$_SESSION['zuletzt'] = time();

/* --- Datenbank --- */
$db = new PDO('sqlite:' . DB_DATEI, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec("CREATE TABLE IF NOT EXISTS mitarbeiter (
  nr TEXT PRIMARY KEY, pw_hash TEXT NOT NULL, pw_stand INTEGER NOT NULL DEFAULT 1,
  muss_aendern INTEGER NOT NULL DEFAULT 1, aktiv INTEGER NOT NULL DEFAULT 1, erstellt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS eintraege (
  id INTEGER PRIMARY KEY AUTOINCREMENT, nr TEXT NOT NULL REFERENCES mitarbeiter(nr),
  datum TEXT NOT NULL, typ TEXT NOT NULL, std REAL, tage REAL, notiz TEXT, erstellt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec('CREATE INDEX IF NOT EXISTS ix_eintraege ON eintraege(nr, datum)');
$db->exec('CREATE TABLE IF NOT EXISTS sperren (schluessel TEXT PRIMARY KEY, fehlversuche INTEGER NOT NULL, bis INTEGER NOT NULL)');

function abfrage(string $sql, array $werte = []): PDOStatement {
  global $db;
  $s = $db->prepare($sql);
  $s->execute($werte);
  return $s;
}

/* --- Schutz gegen Passwort-Raten: nach MAX_FEHLVERSUCHE Fehlern SPERR_MINUTEN warten --- */
function pruefeSperre(string $k): void {
  $r = abfrage('SELECT fehlversuche, bis FROM sperren WHERE schluessel = ?', [$k])->fetch();
  if ($r && $r['fehlversuche'] >= MAX_FEHLVERSUCHE && $r['bis'] > time())
    fehler('Zu viele Fehlversuche. Bitte in ' . ceil(($r['bis'] - time()) / 60) . ' Minuten nochmals versuchen.', 429);
}
function fehlversuch(string $k): void {
  $jetzt = time();
  abfrage('INSERT INTO sperren (schluessel, fehlversuche, bis) VALUES (?, 1, ?)
    ON CONFLICT(schluessel) DO UPDATE SET fehlversuche = CASE WHEN bis < ? THEN 1 ELSE fehlversuche + 1 END, bis = ?',
    [$k, $jetzt + SPERR_MINUTEN * 60, $jetzt, $jetzt + SPERR_MINUTEN * 60]);
}
function entsperren(string $k): void { abfrage('DELETE FROM sperren WHERE schluessel = ?', [$k]); }

/* --- Eingaben prüfen --- */
function text(string $feld, int $max = 200): string {
  global $in;
  $w = trim((string)($in[$feld] ?? ''));
  if (zeichen($w) > $max) fehler("Eingabe «{$feld}» ist zu lang.");
  return $w;
}
function nummer(): string {
  $nr = strtoupper(text('nr', 20));
  if (!preg_match('/^[A-Z0-9-]{1,20}$/', $nr)) fehler('Ungültige Personalnummer.');
  return $nr;
}
function zeichen(string $s): int { return preg_match_all('/./us', $s); }
function jahrMonat(): string {
  global $in;
  $j = (int)($in['jahr'] ?? 0); $m = (int)($in['monat'] ?? 0);
  if ($j < 2000 || $j > 2100 || $m < 1 || $m > 12) fehler('Ungültiger Monat.');
  return sprintf('%04d-%02d', $j, $m);
}

/* --- Wer ist angemeldet? --- */
function mitarbeiterin(bool $auchWennPwAendern = false): array {
  if (empty($_SESSION['nr'])) fehler('Bitte anmelden.', 401);
  $u = abfrage('SELECT * FROM mitarbeiter WHERE nr = ?', [$_SESSION['nr']])->fetch();
  // Abgemeldet, wenn deaktiviert oder das Passwort inzwischen zurückgesetzt wurde
  if (!$u || !$u['aktiv'] || (int)$u['pw_stand'] !== (int)($_SESSION['pw_stand'] ?? 0)) { unset($_SESSION['nr']); fehler('Bitte neu anmelden.', 401); }
  if ($u['muss_aendern'] && !$auchWennPwAendern) fehler('Bitte zuerst ein eigenes Passwort festlegen.', 403);
  return $u;
}
function admin(): void { if (empty($_SESSION['admin'])) fehler('Bitte als Admin anmelden.', 401); }

const TYPEN = ['arbeit', 'ferien', 'krank', 'unfall', 'andere'];

switch ($aktion) {

  /* ===== Allgemein ===== */
  case 'info':
    antwort(['firma' => FIRMA, 'minLaenge' => MIN_LAENGE]);

  /* ===== Mitarbeiterinnen ===== */
  case 'login': {
    $nr = nummer(); $pw = (string)($in['pw'] ?? '');
    pruefeSperre("nr:$nr");
    $u = abfrage('SELECT * FROM mitarbeiter WHERE nr = ? AND aktiv = 1', [$nr])->fetch();
    if (!$u || !password_verify($pw, $u['pw_hash'])) { fehlversuch("nr:$nr"); fehler('Personalnummer oder Passwort falsch.', 401); }
    entsperren("nr:$nr");
    session_regenerate_id(true);
    $_SESSION['nr'] = $u['nr']; $_SESSION['pw_stand'] = $u['pw_stand'];
    antwort(['nr' => $u['nr'], 'mussAendern' => (bool)$u['muss_aendern']]);
  }

  case 'ich': {
    $u = mitarbeiterin(true);
    antwort(['nr' => $u['nr'], 'mussAendern' => (bool)$u['muss_aendern']]);
  }

  case 'pw_aendern': {
    $u = mitarbeiterin(true);
    $alt = (string)($in['alt'] ?? ''); $neu = (string)($in['neu'] ?? '');
    // Beim ersten Mal wurde das Startpasswort eben erst bei der Anmeldung geprüft
    if (!$u['muss_aendern'] && !password_verify($alt, $u['pw_hash'])) fehler('Das bisherige Passwort ist falsch.');
    if (zeichen($neu) < MIN_LAENGE) fehler('Das Passwort muss mindestens ' . MIN_LAENGE . ' Zeichen haben.');
    if (zeichen($neu) > 200) fehler('Das Passwort ist zu lang.');
    if (password_verify($neu, $u['pw_hash'])) fehler('Bitte ein anderes Passwort als das bisherige wählen.');
    abfrage('UPDATE mitarbeiter SET pw_hash = ?, muss_aendern = 0, pw_stand = pw_stand + 1 WHERE nr = ?', [password_hash($neu, PASSWORD_DEFAULT), $u['nr']]);
    $_SESSION['pw_stand'] = (int)$u['pw_stand'] + 1;
    antwort(['ok' => true]);
  }

  case 'logout':
    $_SESSION = [];
    session_destroy();
    antwort(['ok' => true]);

  case 'monat': {
    $u = mitarbeiterin();
    $ym = jahrMonat();
    antwort(['eintraege' => abfrage('SELECT id, datum, typ, std, tage, notiz FROM eintraege WHERE nr = ? AND datum LIKE ? ORDER BY datum, id', [$u['nr'], "$ym-%"])->fetchAll()]);
  }

  case 'eintrag_neu': {
    $u = mitarbeiterin();
    $datum = text('datum', 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $t) || !checkdate((int)$t[2], (int)$t[3], (int)$t[1])) fehler('Ungültiges Datum.');
    $typ = text('typ', 20);
    if (!in_array($typ, TYPEN, true)) fehler('Ungültige Art.');
    $std = isset($in['std']) ? round((float)$in['std'], 2) : null;
    $tage = isset($in['tage']) ? (float)$in['tage'] : null;
    if ($typ === 'arbeit') { $tage = null; if (!($std > 0 && $std <= 24)) fehler('Bitte gültige Stunden eingeben (0–24).'); }
    elseif ($tage !== null) { $std = null; if (!in_array($tage, [0.5, 1.0], true)) fehler('Ungültiger Umfang.'); }
    elseif (!($std > 0 && $std <= 24)) fehler('Bitte gültige Stunden eingeben (0–24).');
    $notiz = text('notiz', 500);
    abfrage('INSERT INTO eintraege (nr, datum, typ, std, tage, notiz) VALUES (?, ?, ?, ?, ?, ?)', [$u['nr'], $datum, $typ, $std, $tage, $notiz ?: null]);
    antwort(['id' => (int)$db->lastInsertId()]);
  }

  case 'eintrag_loeschen': {
    $u = mitarbeiterin();
    abfrage('DELETE FROM eintraege WHERE id = ? AND nr = ?', [(int)($in['id'] ?? 0), $u['nr']]);
    antwort(['ok' => true]);
  }

  /* ===== Admin ===== */
  case 'admin_login': {
    pruefeSperre('admin');
    if (!password_verify((string)($in['pw'] ?? ''), ADMIN_PW_HASH)) { fehlversuch('admin'); fehler('Falsches Admin-Passwort.', 401); }
    entsperren('admin');
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    antwort(['ok' => true]);
  }

  case 'admin_ich':
    admin();
    antwort(['ok' => true]);

  case 'ma_liste':
    admin();
    antwort(['mitarbeiter' => abfrage('SELECT nr, muss_aendern AS mussAendern, aktiv, erstellt FROM mitarbeiter ORDER BY nr')->fetchAll()]);

  case 'ma_anlegen': {
    admin();
    $nr = nummer(); $pw = (string)($in['startpw'] ?? '');
    if (zeichen($pw) < 8) fehler('Das Startpasswort muss mindestens 8 Zeichen haben.');
    if (abfrage('SELECT 1 FROM mitarbeiter WHERE nr = ?', [$nr])->fetch()) fehler('Diese Personalnummer gibt es schon.');
    abfrage('INSERT INTO mitarbeiter (nr, pw_hash) VALUES (?, ?)', [$nr, password_hash($pw, PASSWORD_DEFAULT)]);
    antwort(['ok' => true]);
  }

  case 'ma_startpw': {
    admin();
    $nr = nummer(); $pw = (string)($in['startpw'] ?? '');
    if (zeichen($pw) < 8) fehler('Das Startpasswort muss mindestens 8 Zeichen haben.');
    $n = abfrage('UPDATE mitarbeiter SET pw_hash = ?, muss_aendern = 1, pw_stand = pw_stand + 1 WHERE nr = ?', [password_hash($pw, PASSWORD_DEFAULT), $nr])->rowCount();
    if (!$n) fehler('Personalnummer nicht gefunden.', 404);
    entsperren("nr:$nr");
    antwort(['ok' => true]);
  }

  case 'ma_aktiv': {
    admin();
    $nr = nummer();
    abfrage('UPDATE mitarbeiter SET aktiv = ?, pw_stand = pw_stand + 1 WHERE nr = ?', [empty($in['aktiv']) ? 0 : 1, $nr]);
    antwort(['ok' => true]);
  }

  case 'admin_monat': {
    admin();
    $ym = jahrMonat();
    antwort([
      'mitarbeiter' => abfrage('SELECT nr, aktiv FROM mitarbeiter ORDER BY nr')->fetchAll(),
      'eintraege' => abfrage('SELECT nr, datum, typ, std, tage, notiz FROM eintraege WHERE datum LIKE ? ORDER BY nr, datum, id', ["$ym-%"])->fetchAll(),
    ]);
  }

  case 'admin_logout':
    $_SESSION = [];
    session_destroy();
    antwort(['ok' => true]);

  default:
    fehler('Unbekannte Aktion.', 404);
}
