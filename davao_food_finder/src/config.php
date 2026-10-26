<?php
// Keep the API key in the server environment, never in browser code or source control.
$envKey = getenv('OPENAI_API_KEY');
define('OPENAI_API_KEY', $envKey !== false ? trim($envKey) : '');
define('OPENAI_MODEL', 'gpt-4o-mini');
