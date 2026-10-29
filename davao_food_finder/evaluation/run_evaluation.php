<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function normalizePlaceName(string $name): string {
    $name = mb_strtolower(trim($name), 'UTF-8');
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? '';
}

function validateGroundTruth(array $data): array {
    $cases = $data['cases'] ?? null;
    if (!is_array($cases) || !$cases) {
        fail('Ground truth has no cases. Add labeled queries to evaluation/ground_truth.json; see evaluation/README.md.');
    }
    $scopeCases = $data['scope_cases'] ?? [];
    if (!is_array($scopeCases)) fail('Ground-truth scope_cases must be an array.');

    $validated = [];
    $seenIds = [];
    foreach ($cases as $index => $case) {
        if (!is_array($case)) fail('Ground-truth case ' . ($index + 1) . ' must be an object.');
        $id = trim((string)($case['id'] ?? ''));
        $query = trim((string)($case['query'] ?? ''));
        $relevantNames = $case['relevant_names'] ?? null;
        if ($id === '' || isset($seenIds[$id])) fail('Every ground-truth case needs a unique, non-empty id.');
        if ($query === '' || mb_strlen($query, 'UTF-8') > 300) fail("Ground-truth case {$id} needs a query of 1-300 characters.");
        if (preg_match('/\bdavao\s+city\b/i', $query) !== 1) fail("In-scope ground-truth query {$id} must explicitly target Davao City.");
        if (!is_array($relevantNames) || !$relevantNames) fail("Ground-truth case {$id} needs at least one manually verified relevant_names entry.");

        $normalizedNames = [];
        foreach ($relevantNames as $name) {
            if (!is_string($name) || trim($name) === '') fail("Ground-truth case {$id} contains an empty or invalid relevant name.");
            $normalized = normalizePlaceName($name);
            if ($normalized !== '') $normalizedNames[$normalized] = true;
        }
        if (!$normalizedNames) fail("Ground-truth case {$id} has no usable relevant restaurant names.");

        $seenIds[$id] = true;
        $validated[] = ['id'=>$id, 'query'=>$query, 'relevant_names'=>$normalizedNames];
    }

    $validatedScopeCases = [];
    foreach ($scopeCases as $index => $case) {
        if (!is_array($case)) fail('Ground-truth scope case ' . ($index + 1) . ' must be an object.');
        $id = trim((string)($case['id'] ?? ''));
        $query = trim((string)($case['query'] ?? ''));
        if ($id === '' || isset($seenIds[$id])) fail('Every scope case needs a unique, non-empty id.');
        if ($query === '' || mb_strlen($query, 'UTF-8') > 300) fail("Scope case {$id} needs a query of 1-300 characters.");
        if (preg_match('/\bdavao\s+city\b/i', $query) === 1) fail("Scope case {$id} must request a place outside Davao City.");
        $seenIds[$id] = true;
        $validatedScopeCases[] = ['id'=>$id, 'query'=>$query];
    }
    return ['cases'=>$validated, 'scope_cases'=>$validatedScopeCases];
}

function precisionAtFive(array $results, array $relevantNames): array {
    $hits = 0;
    $seen = [];
    $places = array_slice($results, 0, 5);
    foreach ($places as $place) {
        $name = is_array($place) ? (string)($place['name'] ?? '') : '';
        $normalized = normalizePlaceName($name);
        if ($normalized !== '' && isset($relevantNames[$normalized]) && !isset($seen[$normalized])) {
            $hits++;
            $seen[$normalized] = true;
        }
    }
    return [$hits / 5, $hits, count($places)];
}

function median(array $values): float {
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) return 0.0;
    $middle = intdiv($count, 2);
    return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
}

function constraintAdherence(array $results): bool {
    foreach (array_slice($results, 0, 5) as $place) {
        $city = is_array($place) ? (string)($place['city'] ?? '') : '';
        if (preg_match('/\bdavao\s+city\b/i', $city) !== 1) return false;
    }
    return true;
}

function requestEvaluation(string $apiUrl, array $case, array $config): array {
    $body = json_encode([
        'query'=>$case['query'],
        'history'=>[],
        'shownNames'=>[],
        'evaluation'=>[
            'provider'=>$config['provider'],
            'temperature'=>$config['temperature'],
            'promptStyle'=>$config['prompt_style'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

    $handle = curl_init($apiUrl);
    if ($handle === false) throw new RuntimeException('Could not initialize cURL.');
    curl_setopt_array($handle, [
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>120,
    ]);

    $startedAt = microtime(true);
    $raw = curl_exec($handle);
    $latency = microtime(true) - $startedAt;
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        return ['latency'=>$latency, 'failure'=>'Connection failed: ' . $curlError, 'data'=>null];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['latency'=>$latency, 'failure'=>"Invalid JSON response (HTTP {$status}).", 'data'=>null];
    }
    if ($status < 200 || $status >= 300) {
        return ['latency'=>$latency, 'failure'=>(string)($data['error'] ?? "HTTP {$status}."), 'data'=>$data];
    }
    if (($data['assistantStatus'] ?? '') !== 'ready') {
        $provider = (string)($config['provider'] ?? 'deepseek');
        $statusMessage = match ($data['assistantStatus'] ?? '') {
            'not_configured' => $provider === 'gpt-4o-mini' ? 'GPT-4o mini is not configured: set OPENAI_API_KEY on the PHP server.' : 'DeepSeek R1 is not configured: run Ollama or set DEEPSEEK_API_KEY.',
            'error' => $provider === 'gpt-4o-mini' ? 'GPT-4o mini failed; check the PHP server error log.' : 'DeepSeek R1 or Playwright failed; check the PHP server error log.',
            default => 'The API did not report a successful AI response.',
        };
        if ($provider === 'gpt-4o-mini') {
            $providerError = $data['metrics']['gpt4o']['error'] ?? null;
            if (is_array($providerError)) {
                $http = (int)($providerError['http'] ?? 0);
                $message = trim((string)($providerError['message'] ?? ''));
                $statusMessage = 'GPT-4o mini failed'
                    . ($http > 0 ? ' with HTTP ' . $http : '')
                    . ($message !== '' ? ': ' . mb_substr($message, 0, 240) : '')
                    . '.';
            }
        }
        return ['latency'=>$latency, 'failure'=>$statusMessage, 'data'=>$data];
    }
    return ['latency'=>$latency, 'failure'=>null, 'data'=>$data];
}

function logGamma(float $z): float {
    $coefficients = [
        676.5203681218851,
        -1259.1392167224028,
        771.32342877765313,
        -176.61502916214059,
        12.507343278686905,
        -0.13857109526572012,
        9.9843695780195716e-6,
        1.5056327351493116e-7,
    ];
    $z -= 1.0;
    $sum = 0.99999999999980993;
    foreach ($coefficients as $index => $coefficient) $sum += $coefficient / ($z + $index + 1.0);
    $t = $z + count($coefficients) - 0.5;
    return 0.9189385332046727 + ($z + 0.5) * log($t) - $t + log($sum);
}

function regularizedGammaQ(float $a, float $x): float {
    if ($x <= 0) return 1.0;
    if ($x < $a + 1.0) {
        $sum = 1.0 / $a;
        $term = $sum;
        $ap = $a;
        for ($i = 1; $i <= 200; $i++) {
            $ap++;
            $term *= $x / $ap;
            $sum += $term;
            if (abs($term) < abs($sum) * 1.0e-14) break;
        }
        $p = $sum * exp(-$x + $a * log($x) - logGamma($a));
        return max(0.0, min(1.0, 1.0 - $p));
    }

    $b = $x + 1.0 - $a;
    $c = 1.0 / 1.0e-300;
    $d = 1.0 / $b;
    $h = $d;
    for ($i = 1; $i <= 200; $i++) {
        $an = -$i * ($i - $a);
        $b += 2.0;
        $d = $an * $d + $b;
        if (abs($d) < 1.0e-300) $d = 1.0e-300;
        $c = $b + $an / $c;
        if (abs($c) < 1.0e-300) $c = 1.0e-300;
        $d = 1.0 / $d;
        $delta = $d * $c;
        $h *= $delta;
        if (abs($delta - 1.0) < 1.0e-14) break;
    }
    $q = exp(-$x + $a * log($x) - logGamma($a)) * $h;
    return max(0.0, min(1.0, $q));
}

function friedmanTest(array $scoresByConfig, int $queryCount): ?array {
    $configCount = count($scoresByConfig);
    if ($configCount < 3 || $queryCount < 2) return null;

    $rankSums = array_fill(0, $configCount, 0.0);
    $tieCorrectionSum = 0.0;
    for ($caseIndex = 0; $caseIndex < $queryCount; $caseIndex++) {
        $scores = [];
        foreach ($scoresByConfig as $configScores) $scores[] = $configScores[$caseIndex];
        $sorted = $scores;
        sort($sorted, SORT_NUMERIC);
        $ranks = [];
        $tieCounts = [];
        $position = 0;
        while ($position < $configCount) {
            $end = $position + 1;
            while ($end < $configCount && $sorted[$end] === $sorted[$position]) $end++;
            $tieCount = $end - $position;
            $averageRank = (($position + 1) + $end) / 2.0;
            for ($i = $position; $i < $end; $i++) $ranks[(string)$sorted[$i]][] = $averageRank;
            $tieCounts[] = $tieCount;
            $position = $end;
        }
        foreach ($scores as $configIndex => $score) {
            $rankSums[$configIndex] += array_shift($ranks[(string)$score]);
        }
        foreach ($tieCounts as $tieCount) $tieCorrectionSum += ($tieCount ** 3) - $tieCount;
    }

    $statistic = (12.0 / ($queryCount * $configCount * ($configCount + 1))) * array_sum(array_map(fn($sum) => $sum ** 2, $rankSums))
        - 3.0 * $queryCount * ($configCount + 1);
    $correction = 1.0 - $tieCorrectionSum / ($queryCount * ($configCount ** 3 - $configCount));
    $statistic = $correction > 0 ? max(0.0, $statistic / $correction) : 0.0;
    $degreesOfFreedom = $configCount - 1;
    return [
        'statistic'=>$statistic,
        'degrees_of_freedom'=>$degreesOfFreedom,
        'p_value'=>regularizedGammaQ($degreesOfFreedom / 2.0, $statistic / 2.0),
    ];
}

function parseArguments(array $arguments): array {
    $options = [
        'require_ai'=>false,
        'quick'=>false,
        'api_url'=>'http://127.0.0.1:8080/api.php',
        'ground_truth'=>__DIR__ . '/ground_truth.json',
        'providers'=>['deepseek'],
    ];
    foreach ($arguments as $argument) {
        if ($argument === '--require-ai') {
            $options['require_ai'] = true;
        } elseif ($argument === '--quick') {
            $options['quick'] = true;
        } elseif (str_starts_with($argument, '--api-url=')) {
            $options['api_url'] = substr($argument, strlen('--api-url='));
        } elseif (str_starts_with($argument, '--ground-truth=')) {
            $options['ground_truth'] = substr($argument, strlen('--ground-truth='));
        } elseif (str_starts_with($argument, '--provider=')) {
            $options['providers'] = [strtolower(trim(substr($argument, strlen('--provider='))))];
        } elseif (str_starts_with($argument, '--providers=')) {
            $options['providers'] = array_values(array_filter(array_map('trim', explode(',', substr($argument, strlen('--providers='))))));
        } else {
            fail("Unknown option {$argument}. Supported options: --quick, --require-ai, --api-url=URL, --ground-truth=PATH, --provider=deepseek|gpt-4o-mini, --providers=deepseek,gpt-4o-mini.");
        }
    }
    $url = filter_var($options['api_url'], FILTER_VALIDATE_URL);
    $host = strtolower((string)parse_url($options['api_url'], PHP_URL_HOST));
    if (!$url || !in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)
        || !in_array(strtolower((string)parse_url($options['api_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
        fail('The evaluation API URL must point to a local PHP server, such as http://127.0.0.1:8081/api.php.');
    }
    foreach ($options['providers'] as $provider) {
        if (!in_array($provider, ['deepseek', 'gpt-4o-mini'], true)) fail('Provider must be deepseek or gpt-4o-mini.');
    }
    $options['providers'] = array_values(array_unique($options['providers']));
    return $options;
}

try {
    if (PHP_SAPI !== 'cli') fail('Run this evaluator from a terminal with PHP CLI.');
    if (!extension_loaded('curl') || !extension_loaded('mbstring')) fail('Enable PHP extensions curl and mbstring before running evaluation.');

    $options = parseArguments(array_slice($argv, 1));
    $groundTruthPath = $options['ground_truth'];
    if (!str_starts_with($groundTruthPath, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $groundTruthPath)) {
        $groundTruthPath = getcwd() . DIRECTORY_SEPARATOR . $groundTruthPath;
    }
    if (!is_file($groundTruthPath)) {
        fail("Ground-truth file not found: {$groundTruthPath}. Copy evaluation/ground_truth.example.json to evaluation/ground_truth.json and add your manually verified labels.");
    }
    $groundTruthJson = (string)file_get_contents($groundTruthPath);
    if (str_starts_with($groundTruthJson, "\xEF\xBB\xBF")) $groundTruthJson = substr($groundTruthJson, 3);
    $groundTruth = json_decode($groundTruthJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($groundTruth)) fail('Ground-truth JSON must contain a top-level object with a cases array.');
    $groundTruth = validateGroundTruth($groundTruth);
    $cases = $groundTruth['cases'];
    $scopeCases = $groundTruth['scope_cases'];

    $configurations = [];
    foreach ($options['providers'] as $provider) {
        $temperatures = $options['quick'] ? [0.0] : [0.0, 0.3, 0.7];
        $promptStyles = $options['quick'] ? ['baseline'] : ['baseline', 'strict_grounding'];
        foreach ($temperatures as $temperature) {
            foreach ($promptStyles as $promptStyle) {
                $configurations[] = [
                    'provider'=>$provider,
                    'id'=>sprintf('%s-T%.1f-%s', strtoupper($provider), $temperature, strtoupper($promptStyle === 'baseline' ? 'BASE' : 'STRICT')),
                    'temperature'=>$temperature,
                    'top_p'=>1.0,
                    'prompt_style'=>$promptStyle,
                ];
            }
        }
    }

    echo "Davao Food Finder hyperparameter evaluation\n";
    echo 'API: ' . $options['api_url'] . "\n";
    echo 'Providers: ' . implode(', ', $options['providers']) . "\n";
    echo 'Davao City relevance queries: ' . count($cases) . "\n";
    echo 'Outside-city scope checks: ' . count($scopeCases) . "\n";
    echo 'Configurations: ' . count($configurations) . ' (' . (count($cases) + count($scopeCases)) * count($configurations) . " total AI requests)\n";
    echo "Precision@5 = relevant unique returned names in the first five local results / 5; failed requests score 0.\n";
    echo $options['quick']
        ? "Quick mode is fixed at Top-P 1.0, temperature 0.0, and baseline prompt style.\n"
        : "Top-P is fixed at 1.0; temperatures and prompt styles are varied by exhaustive grid search.\n";
    if ($options['require_ai']) echo "AI responses are required; non-ready responses count as failures.\n";

    $summary = [];
    $scoresByConfig = [];
    $anyAiFailure = false;
    foreach ($configurations as $config) {
        echo "\n--- CONFIG: {$config['id']} | Provider={$config['provider']} | Temp={$config['temperature']} | Top-P={$config['top_p']} | Prompt={$config['prompt_style']} ---\n";
        $precisionSum = 0.0;
        $latencySum = 0.0;
        $latencies = [];
        $failures = 0;
        $configScores = [];
        $playwrightSuccesses = 0;
        $playwrightRequests = 0;
        $playwrightEvidenceCount = 0;
        $providerSuccesses = 0;
        $providerRequests = 0;
        $componentLatencySum = 0.0;

        foreach ($cases as $index => $case) {
            $response = requestEvaluation($options['api_url'], $case, $config);
            $data = $response['data'];
            $latency = $response['latency'];
            $latencySum += $latency;
            $latencies[] = $latency;
            $failure = $response['failure'];
            $results = is_array($data['results'] ?? null) ? $data['results'] : [];
            $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
            $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
            if ($playwright) {
                $playwrightRequests++;
                if (($playwright['success'] ?? false) === true) $playwrightSuccesses++;
                $playwrightEvidenceCount += (int)($playwright['result_count'] ?? 0);
            }
            $providerKey = $config['provider'] === 'gpt-4o-mini' ? 'gpt4o' : 'deepseek';
            $providerMetrics = is_array($metrics[$providerKey] ?? null) ? $metrics[$providerKey] : [];
            if ($providerMetrics) {
                $providerRequests++;
                if (($providerMetrics['success'] ?? false) === true) $providerSuccesses++;
            }
            $componentLatencySum += (float)($metrics['total_latency_ms'] ?? 0) / 1000.0;
            if ($failure !== null) {
                $failures++;
                $anyAiFailure = true;
                $score = 0.0;
                $hits = 0;
                $returnedCount = 0;
                $constraint = 'N/A';
            } else {
                [$score, $hits, $returnedCount] = precisionAtFive($results, $case['relevant_names']);
                $constraint = constraintAdherence($results) ? 'YES' : 'NO';
            }
            $precisionSum += $score;
            $configScores[] = $score;
            $label = $failure === null ? 'AI ready' : 'AI error: ' . $failure;
            printf(
                "[%d/%d] Query %s | P@5 %.2f%% (%d/5; returned %d) | Latency %.2f s | Constraint %s | %s\n",
                $index + 1,
                count($cases),
                $case['id'],
                $score * 100,
                $hits,
                $returnedCount,
                $latency,
                $constraint,
                $label
            );
        }

        $meanPrecision = $precisionSum / count($cases);
        $scopeViolations = 0;
        foreach ($scopeCases as $scopeCase) {
            $response = requestEvaluation($options['api_url'], $scopeCase, $config);
            $latency = $response['latency'];
            $latencySum += $latency;
            $latencies[] = $latency;
            $failure = $response['failure'];
            if ($failure !== null) {
                $failures++;
                $anyAiFailure = true;
                $scopeResult = 'N/A (AI error)';
                $constraint = 'N/A';
            } else {
                $data = $response['data'];
                $results = is_array($data['results'] ?? null) ? $data['results'] : [];
                $webResults = is_array($data['webResults'] ?? null) ? $data['webResults'] : [];
                $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
                $playwright = is_array($metrics['playwright'] ?? null) ? $metrics['playwright'] : [];
                if ($playwright) {
                    $playwrightRequests++;
                    if (($playwright['success'] ?? false) === true) $playwrightSuccesses++;
                    $playwrightEvidenceCount += (int)($playwright['result_count'] ?? 0);
                }
                $providerKey = $config['provider'] === 'gpt-4o-mini' ? 'gpt4o' : 'deepseek';
                $providerMetrics = is_array($metrics[$providerKey] ?? null) ? $metrics[$providerKey] : [];
                if ($providerMetrics) {
                    $providerRequests++;
                    if (($providerMetrics['success'] ?? false) === true) $providerSuccesses++;
                }
                $scopePassed = ($data['outOfScope'] ?? false) === true && !$results && !$webResults;
                $constraint = $scopePassed ? 'PASS' : 'FAIL';
                $scopeResult = $scopePassed ? 'correctly refused' : 'returned out-of-city recommendations';
                if (!$scopePassed) $scopeViolations++;
            }
            printf(
                "[Scope %s] P@5 N/A | Latency %.2f s | Davao-only constraint %s | %s%s\n",
                $scopeCase['id'],
                $latency,
                $constraint,
                $response['failure'] === null ? 'AI ready; ' : '',
                $failure !== null ? $failure : $scopeResult
            );
        }
        $totalRequests = count($cases) + count($scopeCases);
        $meanLatency = $latencySum / $totalRequests;
        $errorRate = $failures / $totalRequests;
        $summary[] = $config + [
            'mean_precision'=>$meanPrecision,
            'mean_latency'=>$meanLatency,
            'median_latency'=>median($latencies),
            'failures'=>$failures,
            'ai_successes'=>$totalRequests - $failures,
            'error_rate'=>$errorRate,
            'scope_violations'=>$scopeViolations,
            'playwright_successes'=>$playwrightSuccesses,
            'playwright_requests'=>$playwrightRequests,
            'playwright_evidence_count'=>$playwrightEvidenceCount,
            'provider_successes'=>$providerSuccesses,
            'provider_requests'=>$providerRequests,
        ];
        $scoresByConfig[] = $configScores;
        printf(
            "Config results: Mean P@5 %.2f%% | Mean latency %.2f s | Median latency %.2f s | AI successes %d/%d | Failures %d/%d (%.2f%%) | Davao-only violations %d\n",
            $meanPrecision * 100,
            $meanLatency,
            median($latencies),
            $totalRequests - $failures,
            $totalRequests,
            $failures,
            $totalRequests,
            $errorRate * 100,
            $scopeViolations
        );
        echo "\nComponent status table for {$config['id']}:\n";
        printf("%-12s | %-8s | %-9s | %-8s\n", 'Component', 'Status', 'Successes', 'Evidence');
        echo str_repeat('-', 52) . "\n";
        printf(
            "%-12s | %-8s | %-9s | %-8s\n",
            $config['provider'] === 'gpt-4o-mini' ? 'GPT-4o mini' : 'DeepSeek R1',
            $providerRequests > 0 && $providerSuccesses > 0 ? 'READY' : 'ERROR',
            $providerSuccesses . '/' . $providerRequests,
            '-'
        );
        printf(
            "%-12s | %-8s | %-9s | %-8d\n",
            'Playwright',
            $playwrightRequests > 0 && $playwrightSuccesses > 0 ? 'SUCCESS' : 'FAILED',
            $playwrightSuccesses . '/' . $playwrightRequests,
            $playwrightEvidenceCount
        );
    }

    echo "\n================ HYPERPARAMETER TUNING SUMMARY ================\n";
    printf("%-16s | %-5s | %-5s | %-16s | %-11s | %-13s | %-13s | %-10s | %-10s\n", 'Config ID', 'Temp', 'Top-P', 'Prompt Style', 'Mean P@5', 'Mean Latency', 'Median Latency', 'Error Rate', 'Scope fails');
    echo str_repeat('-', 128) . "\n";
    foreach ($summary as $row) {
        printf(
            "%-16s | %-5.1f | %-5.1f | %-16s | %10.2f%% | %10.2f s | %11.2f s | %8.2f%% | %10d\n",
            $row['id'],
            $row['temperature'],
            $row['top_p'],
            $row['prompt_style'],
            $row['mean_precision'] * 100,
            $row['mean_latency'],
            $row['median_latency'],
            $row['error_rate'] * 100,
            $row['scope_violations']
        );
    }
    echo "\n================ COMPONENT SUMMARY TABLE ================\n";
    printf("%-16s | %-8s | %-13s | %-10s\n", 'Component', 'Status', 'Success Rate', 'Evidence');
    echo str_repeat('-', 56) . "\n";
    $deepseekSuccesses = $deepseekRequests = $gptSuccesses = $gptRequests = $playwrightSuccesses = $playwrightRequests = 0;
    $playwrightEvidenceCount = 0;
    foreach ($summary as $row) {
        if ($row['provider'] === 'deepseek') {
            $deepseekSuccesses += (int)$row['provider_successes'];
            $deepseekRequests += (int)$row['provider_requests'];
        } else {
            $gptSuccesses += (int)$row['provider_successes'];
            $gptRequests += (int)$row['provider_requests'];
        }
        $playwrightSuccesses += (int)$row['playwright_successes'];
        $playwrightRequests += (int)$row['playwright_requests'];
        $playwrightEvidenceCount += (int)$row['playwright_evidence_count'];
    }
    if ($deepseekRequests > 0) printf("%-16s | %-8s | %11.2f%% | %-10s\n", 'DeepSeek R1', $deepseekSuccesses > 0 ? 'READY' : 'ERROR', $deepseekSuccesses / $deepseekRequests * 100, '-');
    if ($gptRequests > 0) printf("%-16s | %-8s | %11.2f%% | %-10s\n", 'GPT-4o mini', $gptSuccesses > 0 ? 'READY' : 'ERROR', $gptSuccesses / $gptRequests * 100, '-');
    if ($playwrightRequests > 0) printf("%-16s | %-8s | %11.2f%% | %-10d\n", 'Playwright', $playwrightSuccesses > 0 ? 'SUCCESS' : 'FAILED', $playwrightSuccesses / $playwrightRequests * 100, $playwrightEvidenceCount);
    $totalRequests = count($cases) + count($scopeCases);
    $validSummary = array_values(array_filter($summary, fn($row) => $row['failures'] < $totalRequests));
    usort($validSummary, fn($a, $b) => ($b['mean_precision'] <=> $a['mean_precision'])
        ?: ($a['error_rate'] <=> $b['error_rate'])
        ?: ($a['scope_violations'] <=> $b['scope_violations'])
        ?: ($a['mean_latency'] <=> $b['mean_latency']));
    if ($validSummary) {
        echo 'Best observed configuration: ' . $validSummary[0]['id'] . " (highest mean P@5, then lowest failure rate, scope violations, and latency).\n";
    } else {
        echo "Best configuration not selected: every configuration failed all AI requests.\n";
    }

    $friedman = friedmanTest($scoresByConfig, count($cases));
    if ($friedman !== null) {
        printf(
            "Friedman test (same queries across configurations): Q=%.4f, df=%d, asymptotic p=%.6f\n",
            $friedman['statistic'],
            $friedman['degrees_of_freedom'],
            $friedman['p_value']
        );
        echo "H0: all configurations have the same rank distribution for per-query P@5. A small p-value indicates a difference; use post-hoc tests before claiming which pairs differ.\n";
    } else {
        echo "Friedman test not reported: it requires at least 3 configurations and 2 ground-truth queries.\n";
    }
    if ($options['require_ai'] && $anyAiFailure) {
        echo "Evaluation completed with AI failures. Check the PHP server configuration and error log; failed requests were assigned P@5 = 0.\n";
        exit(2);
    }
} catch (JsonException $exception) {
    fail('Could not decode ground-truth JSON: ' . $exception->getMessage());
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
