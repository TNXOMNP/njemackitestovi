<?php
/*
  leaderboard.php — čuva i vraća rezultate kviza
  Podaci se čuvaju u fajlu rezultati.json u istom folderu.
  Odvojeno po nivou (a1, a2, b1). Pamti se max 10 najboljih po procentu.

  Upotreba:
   GET  leaderboard.php?level=a1            -> vraća top 10 za nivo
   POST leaderboard.php  (JSON tijelo)      -> dodaje rezultat
        { "level":"a1", "name":"Marko", "percent":80, "answered":12, "total":1000 }
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('DATA_FILE', __DIR__ . '/rezultati.json');
define('VALID_LEVELS', ['a1','a2','b1']);
define('MAX_ENTRIES', 10);

/* Učitaj sve rezultate iz fajla */
function load_all() {
    if (!file_exists(DATA_FILE)) return ['a1'=>[], 'a2'=>[], 'b1'=>[]];
    $raw = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    foreach (VALID_LEVELS as $lv) {
        if (!isset($data[$lv]) || !is_array($data[$lv])) $data[$lv] = [];
    }
    return $data;
}

/* Snimi sve rezultate (uz zaključavanje fajla da se ne pokvari pri istovremenom upisu) */
function save_all($data) {
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/* Sortiraj nivo: prvo po procentu (veći bolji), pa po broju odgovorenih (više bolje) */
function sort_level(&$entries) {
    usort($entries, function($a, $b) {
        if ($b['percent'] === $a['percent']) {
            return ($b['answered'] ?? 0) <=> ($a['answered'] ?? 0);
        }
        return $b['percent'] <=> $a['percent'];
    });
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $level = $_GET['level'] ?? '';
    if (!in_array($level, VALID_LEVELS, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nepoznat nivo.']);
        exit;
    }
    $data = load_all();
    $list = $data[$level];
    sort_level($list);
    echo json_encode(['level' => $level, 'entries' => array_slice($list, 0, MAX_ENTRIES)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neispravni podaci.']);
        exit;
    }

    $level    = $body['level'] ?? '';
    $name     = trim((string)($body['name'] ?? ''));
    $percent  = (int)round($body['percent'] ?? -1);
    $answered = (int)($body['answered'] ?? 0);
    $total    = (int)($body['total'] ?? 0);

    /* Validacija */
    if (!in_array($level, VALID_LEVELS, true)) {
        http_response_code(400); echo json_encode(['error' => 'Nepoznat nivo.']); exit;
    }
    if ($name === '') { $name = 'Anonimno'; }
    // Ograniči dužinu imena (radi i bez mbstring ekstenzije)
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 20, 'UTF-8');
    } else {
        $name = substr($name, 0, 40); // bajtovi; bezbjedna gornja granica
    }
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    if ($percent < 0 || $percent > 100) {
        http_response_code(400); echo json_encode(['error' => 'Neispravan procenat.']); exit;
    }

    /* Uslov: bar 30% svih riječi odigrano */
    $qualifies = ($total > 0) && ($answered >= ceil($total * 0.30));

    $data = load_all();

    if ($qualifies) {
        $data[$level][] = [
            'name'     => $name,
            'percent'  => $percent,
            'answered' => $answered,
            'total'    => $total,
            'date'     => date('c'),
        ];
        sort_level($data[$level]);
        $data[$level] = array_slice($data[$level], 0, MAX_ENTRIES);
        save_all($data);
    }

    /* Vrati osvježenu tabelu + da li je igrač ušao */
    $list = $data[$level];
    sort_level($list);
    echo json_encode([
        'qualified' => $qualifies,
        'level'     => $level,
        'entries'   => array_slice($list, 0, MAX_ENTRIES),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metod nije podržan.']);
