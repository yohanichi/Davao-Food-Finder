<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/config.php';
require_once __DIR__ . '/src/search.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Use POST for searches.'); }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $query = trim((string)($body['query'] ?? ''));
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -10) : [];
    $shownNames = is_array($body['shownNames'] ?? null) ? array_slice($body['shownNames'], 0, 30) : [];
    $useLocalFallback = ($body['useLocalFallback'] ?? false) === true;
    if ($query === '' || mb_strlen($query) > 300) { http_response_code(422); throw new RuntimeException('Enter a search query up to 300 characters.'); }

    $localReply = localConversationReply($query);
    if ($localReply !== null) {
        echo json_encode([
            'answer'=>$localReply,
            'assistantStatus'=>'ready',
            'modelUsed'=>'',
            'outOfScope'=>true,
            'sources'=>[],
            'webResults'=>[],
            'results'=>[],
            'count'=>0,
            'localResponse'=>true,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
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
    if ($useLocalFallback) {
        $results = array_map('publicPlace', array_slice($candidates, 0, 10));
        foreach ($results as &$result) unset($result['_id'], $result['store_id'], $result['source']); unset($result);
        echo json_encode([
            'answer'=>buildRecommendationAnswer($results),
            'assistantStatus'=>'local_fallback',
            'modelUsed'=>'',
            'outOfScope'=>false,
            'sources'=>[],
            'webResults'=>[],
            'results'=>$results,
            'count'=>count($results),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    [$answer, $rankedIds, $assistantStatus, $sources, $webResults, $outOfScope, $modelUsed] = answerWithOpenAI($query, $history, $candidates);
    if ($assistantStatus === 'error' || $assistantStatus === 'not_configured') {
        echo json_encode([
            'answer'=>'',
            'assistantStatus'=>$assistantStatus,
            'modelUsed'=>'',
            'outOfScope'=>false,
            'sources'=>[],
            'webResults'=>[],
            'results'=>[],
            'count'=>0,
            'localFallbackAvailable'=>true,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($outOfScope) $candidates = [];
    $byId = [];
    foreach ($candidates as $p) $byId[$p['_id']] = $p;
    $results = [];
    foreach ($rankedIds as $id) if (isset($byId[$id])) $results[] = publicPlace($byId[$id]);
    $results = array_slice($results, 0, 20);
    foreach ($results as &$r) unset($r['_id'], $r['store_id'], $r['source']); unset($r);
    if (!$outOfScope && !$webResults) {
        echo json_encode([
            'answer'=>'',
            'assistantStatus'=>'no_verified_online_results',
            'modelUsed'=>$modelUsed,
            'outOfScope'=>false,
            'sources'=>[],
            'webResults'=>[],
            'results'=>[],
            'count'=>0,
            'localFallbackAvailable'=>true,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if (!$outOfScope) $answer = buildRecommendationAnswer($webResults);
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

function localConversationReply(string $query): ?string {
    $normalized = mb_strtolower(trim($query, " \t\n\r\0\x0B.!?"));
    if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|thanks|thank you)$/', $normalized)) {
        return 'Hello! Tell me what food or restaurant you are looking for in Davao City, and I will help you find options.';
    }
    if (in_array($normalized, ['help','what can you do','what do you do'], true)) {
        return 'I can help you find restaurants and food places in Davao City. Ask for a cuisine, dish, budget, dietary preference, or atmosphere.';
    }
    return null;
}
