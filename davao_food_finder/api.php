<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/search.php';
require_once __DIR__ . '/src/web_reasoner.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Use POST for searches.'); }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim((string)($body['query'] ?? ''));
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -10) : [];
    $shownNames = is_array($body['shownNames'] ?? null) ? array_slice($body['shownNames'], 0, 30) : [];
    if ($query === '' || mb_strlen($query) > 300) { http_response_code(422); throw new RuntimeException('Enter a search query up to 300 characters.'); }
    $openAIOptions = [];
    $reasoningProvider = 'deepseek';
    if (isset($body['evaluation'])) {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            throw new RuntimeException('Evaluation configuration is only available to local requests.');
        }
        $evaluation = $body['evaluation'];
        if (!is_array($evaluation)) {
            http_response_code(422);
            throw new RuntimeException('Invalid evaluation configuration.');
        }
        $temperature = $evaluation['temperature'] ?? null;
        $promptStyle = $evaluation['promptStyle'] ?? null;
        $provider = strtolower(trim((string)($evaluation['provider'] ?? 'deepseek')));
        if (!is_numeric($temperature) || (float)$temperature < 0 || (float)$temperature > 2
            || !in_array($promptStyle, ['baseline', 'strict_grounding'], true)
            || !in_array($provider, ['deepseek', 'gpt-4o-mini'], true)) {
            http_response_code(422);
            throw new RuntimeException('Invalid evaluation configuration.');
        }
        $openAIOptions = ['temperature'=>(float)$temperature, 'promptStyle'=>$promptStyle];
        $reasoningProvider = $provider;
    }

    // Cache the expensive CSV import between requests. Delete data/.places-cache.json to rebuild it.
    $cacheFile = __DIR__ . '/data/.places-cache.json';
    $signature = datasetSignature(__DIR__ . '/data');
    $places = [];
    if (is_file($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cache) && ($cache['signature'] ?? '') === $signature && is_array($cache['places'] ?? null)) $places = $cache['places'];
    }
    if (!$places) {
        $places = loadDavaoPlaces(__DIR__ . '/data');
        @file_put_contents($cacheFile, json_encode(['signature'=>$signature,'places'=>$places], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }
    if (!$places) throw new RuntimeException('No Davao City records found. Confirm that your CSV files are in data/ and that their city field contains Davao City.');

    // For short follow-ups, retrieve candidates using the previous substantive request.
    $searchQuery = $query;
    if (isContinuationQuery($query)) {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $previousQuery = trim((string)($history[$i]['content'] ?? ''));
            if (($history[$i]['role'] ?? '') === 'user' && $previousQuery !== '' && !isContinuationQuery($previousQuery)) {
                $searchQuery = $previousQuery;
                break;
            }
        }
    }
    $candidates = shortlistPlaces($places, $searchQuery, 100);
    if (preg_match('/\b(more|other|another|alternatives?)\b/i', $query) && $shownNames) {
        $seenNames = array_fill_keys(array_map(fn($n) => mb_strtolower(trim((string)$n)), $shownNames), true);
        $candidates = array_values(array_filter($candidates, fn($p) => !isset($seenNames[mb_strtolower(trim((string)$p['name']))])));
    }
    $evidence = collectPlaywrightEvidence('Davao City ' . $searchQuery, __DIR__ . '/data/.web-cache', 43200);
    if ($reasoningProvider === 'gpt-4o-mini') {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithGPT4oMini($query, $history, $candidates, $evidence, $openAIOptions);
    } else {
        [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithHybridPipeline($query, $history, $candidates, $evidence, $openAIOptions);
    }
    if ($outOfScope) $candidates = [];
    $byId = [];
    foreach ($candidates as $p) $byId[$p['_id']] = $p;
    $results = [];
    foreach ($rankedIds as $id) if (isset($byId[$id])) $results[] = publicPlace($byId[$id]);
    if (!$outOfScope && !$results) $results = array_map('publicPlace', array_slice($candidates, 0, 10));
    $results = array_slice($results, 0, 20);
    foreach ($results as &$r) unset($r['_id'], $r['store_id'], $r['source']); unset($r);
    echo json_encode(['answer'=>$answer, 'assistantStatus'=>$assistantStatus, 'modelUsed'=>$modelUsed, 'outOfScope'=>$outOfScope, 'sources'=>$sources, 'webResults'=>$webResults, 'results'=>$results, 'count'=>count($results)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function datasetSignature(string $dir): string {
    $items = [];
    foreach (glob($dir . '/*.{csv,xlsx,xls}', GLOB_BRACE) ?: [] as $f) $items[] = basename($f) . ':' . filesize($f) . ':' . filemtime($f);
    sort($items);
    return hash('sha256', implode('|', $items));
}

function isContinuationQuery(string $query): bool {
    return preg_match('/^(show me\s+)?(some\s+)?(more|other|another|similar|cheaper|affordable|those|ones|alternatives?)(\s+(ones|options|places|choices|restaurants))?[.!?]*$/i', trim($query)) === 1
        || preg_match('/^(what about|how about)\s+(them|those|the others|similar ones|cheaper ones)[.!?]*$/i', trim($query)) === 1;
}
