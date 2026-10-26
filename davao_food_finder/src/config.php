<?php
// Keep the API key in the server environment, never in browser code or source control.
$envKey = getenv('OPENAI_API_KEY');
define('OPENAI_API_KEY', $envKey !== false ? trim($envKey) : '');
$envModel = getenv('OPENAI_MODEL');
define('OPENAI_MODEL', $envModel !== false && trim($envModel) !== '' ? trim($envModel) : 'gpt-5.5');
