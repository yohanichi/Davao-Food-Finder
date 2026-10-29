<?php
// DeepSeek configuration stays on the server. Ollama is the free local default;
// set DEEPSEEK_API_KEY to use the hosted DeepSeek-compatible API instead.
$apiKey = getenv('DEEPSEEK_API_KEY');
$baseUrl = getenv('DEEPSEEK_BASE_URL');
$model = getenv('DEEPSEEK_MODEL');
define('DEEPSEEK_API_KEY', $apiKey !== false ? trim($apiKey) : '');
define('DEEPSEEK_BASE_URL', $baseUrl !== false && trim($baseUrl) !== '' ? trim($baseUrl) : 'http://127.0.0.1:11434');
define('DEEPSEEK_MODEL', $model !== false && trim($model) !== '' ? trim($model) : 'deepseek-r1:8b');

$openAIKey = getenv('OPENAI_API_KEY');
$openAIModel = getenv('OPENAI_MODEL');
define('OPENAI_API_KEY', $openAIKey !== false ? trim($openAIKey) : '');
define('OPENAI_MODEL', $openAIModel !== false && trim($openAIModel) !== '' ? trim($openAIModel) : 'gpt-4o-mini');
