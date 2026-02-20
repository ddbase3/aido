#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace CliTool;

use Exception;

class AiToolExecutor {
	private string $openaiApiKey;
	private array $history = [];
	private ?string $historyFile;
	private bool $historyEnabled;

	private string $model;
	private int $maxTokens;

	private int $depth;
	private int $maxDepth;

	// API resilience knobs (intentionally simple defaults).
	private int $apiMaxRetries;
	private int $apiPacingMs;
	private int $apiBackoffBaseMs;
	private int $apiBackoffMaxMs;

	public function __construct(string $openaiApiKey, string $model, int $maxTokens, ?string $historyFile, bool $historyEnabled, int $depth, int $maxDepth) {
		$this->openaiApiKey = $openaiApiKey;
		$this->model = $model;
		$this->maxTokens = $maxTokens;
		$this->historyFile = $historyFile;
		$this->historyEnabled = $historyEnabled;
		$this->depth = $depth;
		$this->maxDepth = $maxDepth;

		// Allow tuning via env without changing config/policy shape.
		$this->apiMaxRetries = $this->envInt('AIDO_API_MAX_RETRIES', 6);
		$this->apiPacingMs = $this->envInt('AIDO_API_PACING_MS', 0); // e.g. 100
		$this->apiBackoffBaseMs = $this->envInt('AIDO_API_BACKOFF_BASE_MS', 400);
		$this->apiBackoffMaxMs = $this->envInt('AIDO_API_BACKOFF_MAX_MS', 15000);

		$this->loadHistory();
	}

	private function envInt(string $key, int $default): int {
		$v = getenv($key);
		if (!is_string($v) || $v === '' || !preg_match('/^-?\d+$/', $v)) {
			return $default;
		}
		return (int) $v;
	}

	private function loadHistory(): void {
		if (!$this->historyEnabled || $this->historyFile === null) {
			$this->history = [];
			return;
		}
		if (!file_exists($this->historyFile)) {
			$this->history = [];
			return;
		}

		$data = json_decode((string) file_get_contents($this->historyFile), true);
		$this->history = is_array($data) ? $data : [];
	}

	private function saveHistory(): void {
		if (!$this->historyEnabled || $this->historyFile === null) {
			return;
		}

		$dir = dirname($this->historyFile);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}

		$json = json_encode($this->history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return;
		}

		file_put_contents($this->historyFile, $json, LOCK_EX);
	}

	/**
	 * Calls OpenAI chat completions with:
	 * - basic retry on rate limit (429)
	 * - optional pacing between requests
	 * Returns decoded JSON array.
	 */
	private function callOpenAiApi(array $messages): array {
		$requestData = [
			'model' => $this->model,
			'messages' => $messages,
			'functions' => [
				[
					'name' => 'run_command',
					'description' => 'Executes a system command and returns the result.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'command' => [
								'type' => 'string',
								'description' => 'The system command to execute.'
							]
						],
						'required' => ['command']
					]
				],
				[
					'name' => 'write_file',
					'description' => 'Writes a text file (UTF-8) to disk. This is an atomic, deterministic write and avoids shell quoting issues.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'path' => [
								'type' => 'string',
								'description' => 'Target file path (relative or absolute).'
							],
							'content' => [
								'type' => 'string',
								'description' => 'Full file content to write.'
							],
							'mkdirp' => [
								'type' => 'boolean',
								'description' => 'Create parent directories if missing.',
								'default' => true
							],
							'overwrite' => [
								'type' => 'boolean',
								'description' => 'Overwrite if file exists.',
								'default' => true
							]
						],
						'required' => ['path', 'content']
					]
				],
				[
					'name' => 'read_file',
					'description' => 'Reads a text file from disk and returns its content.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'path' => [
								'type' => 'string',
								'description' => 'File path to read (relative or absolute).'
							],
							'max_bytes' => [
								'type' => 'integer',
								'description' => 'Optional cap to avoid reading huge files.',
								'default' => 200000
							]
						],
						'required' => ['path']
					]
				],
				[
					'name' => 'file_info',
					'description' => 'Returns basic file info (exists, size, sha256, first bytes) for verification.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'path' => [
								'type' => 'string',
								'description' => 'File path (relative or absolute).'
							],
							'head_bytes' => [
								'type' => 'integer',
								'description' => 'How many bytes to read from the beginning.',
								'default' => 16
							]
						],
						'required' => ['path']
					]
				]
			],
			'max_tokens' => $this->maxTokens
		];

		// Optional pacing between API calls to reduce request bursts (does not solve TPM alone).
		if ($this->apiPacingMs > 0) {
			usleep($this->apiPacingMs * 1000);
		}

		$attempt = 0;
		$lastErrorMessage = null;

		while (true) {
			$attempt++;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

// Stabilität: HTTP/1.1 erzwingen (vermeidet seltene HTTP/2 stalls)
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

// Optional: IPv4 erzwingen (nur wenn du sporadische Netz-/Route-Probleme hast)
// curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

// Dein Low-Speed-Wächter war zu aggressiv (15s). Lass ihn drin, aber realistisch.
curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1); // bytes/sec
curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 120); // seconds

$payload = json_encode($requestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($payload === false) {
	curl_close($ch);
	throw new Exception('JSON encode failed: ' . json_last_error_msg());
}

curl_setopt($ch, CURLOPT_HTTPHEADER, [
	'Content-Type: application/json',
	'Authorization: Bearer ' . $this->openaiApiKey,
	'Accept: application/json',
	'Expect:' // verhindert 100-continue Edge-Cases
]);

curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
if ($response === false) {
	$err = curl_error($ch);
	$errno = curl_errno($ch);
	curl_close($ch);
	throw new Exception('cURL Error (' . $errno . '): ' . $err);
}

$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

			$rawHeaders = substr((string) $response, 0, $headerSize);
			$rawBody = substr((string) $response, $headerSize);

			$decoded = json_decode((string) $rawBody, true);
			if (!is_array($decoded)) {
				throw new Exception('Invalid API Response: ' . $rawBody);
			}

			// OpenAI style error payload.
			if (isset($decoded['error'])) {
				$lastErrorMessage = (string) ($decoded['error']['message'] ?? 'Unknown error');

				// Retry only on rate limit (HTTP 429) with backoff.
				if ($httpCode === 429 && $attempt <= $this->apiMaxRetries) {
					$waitMs = $this->computeRateLimitWaitMs($rawHeaders, $lastErrorMessage, $attempt);
					usleep($waitMs * 1000);
					continue;
				}

				// No retry -> throw.
				throw new Exception('OpenAI Error: ' . $lastErrorMessage);
			}

			return $decoded;
		}
	}

	private function computeRateLimitWaitMs(string $rawHeaders, string $message, int $attempt): int {
		// Prefer Retry-After header if present.
		$retryAfterSeconds = $this->parseRetryAfterSeconds($rawHeaders);
		if ($retryAfterSeconds !== null) {
			$ms = (int) ceil($retryAfterSeconds * 1000);
			return max(250, min($ms, $this->apiBackoffMaxMs));
		}

		// Parse common OpenAI message: "Please try again in 9.86s."
		$parsed = $this->parseTryAgainSeconds($message);
		if ($parsed !== null) {
			$ms = (int) ceil($parsed * 1000);
			return max(250, min($ms, $this->apiBackoffMaxMs));
		}

		// Fallback exponential backoff with jitter.
		$base = max(50, $this->apiBackoffBaseMs);
		$exp = (int) min($this->apiBackoffMaxMs, $base * (2 ** max(0, $attempt - 1)));
		$jitter = random_int(0, (int) max(1, $base));
		$ms = min($this->apiBackoffMaxMs, $exp + $jitter);
		return max(250, $ms);
	}

	private function parseRetryAfterSeconds(string $rawHeaders): ?float {
		$lines = preg_split("/\r\n|\n|\r/", $rawHeaders);
		if (!is_array($lines)) {
			return null;
		}
		foreach ($lines as $line) {
			$line = trim((string) $line);
			if ($line === '') {
				continue;
			}
			if (stripos($line, 'Retry-After:') === 0) {
				$val = trim(substr($line, strlen('Retry-After:')));
				if (preg_match('/^\d+(?:\.\d+)?$/', $val)) {
					return (float) $val;
				}
				if (preg_match('/^\d+$/', $val)) {
					return (float) ((int) $val);
				}
			}
		}
		return null;
	}

	private function parseTryAgainSeconds(string $message): ?float {
		if (preg_match('/try again in\s+(\d+(?:\.\d+)?)s/i', $message, $m)) {
			return (float) $m[1];
		}
		return null;
	}

	private function isSelfCall(string $command): bool {
		$cmd = ltrim($command);

		// Ignore leading env assignments like:
		// A=1 B=2 AIDO_DEPTH=1 aido ...
		$cmd = preg_replace('/^(?:[A-Za-z_][A-Za-z0-9_]*=\\S+\\s+)*/', '', $cmd);
		$cmd = ltrim((string) $cmd);

		if ($cmd === 'aido' || str_starts_with($cmd, 'aido ')) {
			return true;
		}

		if ($cmd === '/usr/local/bin/aido' || str_starts_with($cmd, '/usr/local/bin/aido ')) {
			return true;
		}

		return false;
	}

	private function decorateSelfCall(string $command): string {
		if (!$this->isSelfCall($command)) {
			return $command;
		}

		if ($this->depth >= $this->maxDepth) {
			return "echo \"Error: recursion depth limit reached (depth={$this->depth}, max_depth={$this->maxDepth})\"";
		}

		$nextDepth = $this->depth + 1;

		// Remove any existing AIDO_DEPTH assignment (if present) so recursion always increments.
		$cmd = ltrim($command);
		$cmd = preg_replace('/\\bAIDO_DEPTH=\\S+\\s*/', '', $cmd, 1);

		// Keep recursion behavior purely policy-driven:
		// we only propagate AIDO_DEPTH so the configured profile for that depth applies.
		return "AIDO_DEPTH={$nextDepth} " . ltrim((string) $cmd);
	}

	private function executeCommand(string $command): string {
		$command = $this->decorateSelfCall($command);

		echo "\033[33m[Executing]: $command\033[0m\n";
		$output = shell_exec($command . ' 2>&1');

		if ($output === null || $output === false) {
			return 'Command executed, but returned no output or failed.';
		}

		return $output;
	}

	private function writeFile(string $path, string $content, bool $mkdirp = true, bool $overwrite = true): string {
		// No restrictions by request (same power model as run_command, but safer for content correctness).
		$dir = dirname($path);
		if ($mkdirp && $dir !== '' && $dir !== '.' && !is_dir($dir)) {
			if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
				return "Error: failed to create directory: {$dir}";
			}
		}

		if (!$overwrite && file_exists($path)) {
			return "Error: file exists and overwrite=false: {$path}";
		}

		$tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
		$bytes = @file_put_contents($tmp, $content, LOCK_EX);
		if ($bytes === false) {
			@unlink($tmp);
			return "Error: failed to write temp file: {$tmp}";
		}

		if (!@rename($tmp, $path)) {
			@unlink($tmp);
			return "Error: failed to move temp file into place: {$path}";
		}

		return "OK: wrote {$bytes} bytes to {$path}";
	}

	private function readFile(string $path, int $maxBytes = 200000): string {
		if (!file_exists($path)) {
			return "Error: file not found: {$path}";
		}
		if (!is_readable($path)) {
			return "Error: file not readable: {$path}";
		}

		$size = @filesize($path);
		if (is_int($size) && $size > $maxBytes) {
			$data = @file_get_contents($path, false, null, 0, $maxBytes);
			if ($data === false) {
				return "Error: failed to read file: {$path}";
			}
			return $data . "\n\n[Truncated: file exceeds max_bytes={$maxBytes}]";
		}

		$data = @file_get_contents($path);
		if ($data === false) {
			return "Error: failed to read file: {$path}";
		}
		return (string) $data;
	}

	private function fileInfo(string $path, int $headBytes = 16): string {
		$info = [
			'path' => $path,
			'exists' => false,
			'is_file' => false,
			'size' => null,
			'sha256' => null,
			'head_hex' => null
		];

		if (!file_exists($path)) {
			return json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $path;
		}

		$info['exists'] = true;
		$info['is_file'] = is_file($path);

		$size = @filesize($path);
		if (is_int($size)) {
			$info['size'] = $size;
		}

		if (is_file($path) && is_readable($path)) {
			$hash = @hash_file('sha256', $path);
			if (is_string($hash)) {
				$info['sha256'] = $hash;
			}

			$fh = @fopen($path, 'rb');
			if (is_resource($fh)) {
				$buf = @fread($fh, max(0, $headBytes));
				fclose($fh);
				if (is_string($buf)) {
					$info['head_hex'] = bin2hex($buf);
				}
			}
		}

		$json = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return $json === false ? 'Error: failed to encode file_info JSON.' : $json;
	}

	private function wrapOutput(string $text, int $lineLength = 100): string {
		return wordwrap($text, $lineLength, "\n", true);
	}

	public function processQuery(string $query, string $sysPromptText, string $runtimeContext, int $toolLoops): string {
		$this->history[] = ['role' => 'user', 'content' => $query];

		$messages = [
			[
				'role' => 'system',
				'content' => $runtimeContext . $sysPromptText
			]
		];

		$messages = array_merge($messages, array_slice($this->history, -10));

		for ($i = 0; $i < $toolLoops; $i++) {
			$response = $this->callOpenAiApi($messages);

			if (isset($response['error'])) {
				throw new Exception('OpenAI Error: ' . ($response['error']['message'] ?? 'Unknown error'));
			}

			$message = $response['choices'][0]['message'] ?? null;
			if (!is_array($message)) {
				throw new Exception('Invalid OpenAI response structure.');
			}

			$messages[] = $message;

			if (!empty($message['function_call'])) {
				$functionName = (string) ($message['function_call']['name'] ?? '');
				$argsJson = (string) ($message['function_call']['arguments'] ?? '{}');
				$arguments = json_decode($argsJson, true);
				$arguments = is_array($arguments) ? $arguments : [];

				if ($functionName === 'run_command' && isset($arguments['command'])) {
					$result = $this->executeCommand((string) $arguments['command']);

					$messages[] = [
						'role' => 'function',
						'name' => 'run_command',
						'content' => $result
					];

					continue;
				}

				if ($functionName === 'write_file' && isset($arguments['path']) && isset($arguments['content'])) {
					$mkdirp = isset($arguments['mkdirp']) ? (bool) $arguments['mkdirp'] : true;
					$overwrite = isset($arguments['overwrite']) ? (bool) $arguments['overwrite'] : true;

					$result = $this->writeFile(
						(string) $arguments['path'],
						(string) $arguments['content'],
						$mkdirp,
						$overwrite
					);

					$messages[] = [
						'role' => 'function',
						'name' => 'write_file',
						'content' => $result
					];

					continue;
				}

				if ($functionName === 'read_file' && isset($arguments['path'])) {
					$maxBytes = isset($arguments['max_bytes']) ? (int) $arguments['max_bytes'] : 200000;
					$maxBytes = max(1, $maxBytes);

					$result = $this->readFile((string) $arguments['path'], $maxBytes);

					$messages[] = [
						'role' => 'function',
						'name' => 'read_file',
						'content' => $result
					];

					continue;
				}

				if ($functionName === 'file_info' && isset($arguments['path'])) {
					$headBytes = isset($arguments['head_bytes']) ? (int) $arguments['head_bytes'] : 16;
					$headBytes = max(0, min(4096, $headBytes));

					$result = $this->fileInfo((string) $arguments['path'], $headBytes);

					$messages[] = [
						'role' => 'function',
						'name' => 'file_info',
						'content' => $result
					];

					continue;
				}
			}

			if (isset($message['content']) && $message['content'] !== null) {
				$finalText = (string) $message['content'];
				$this->history[] = ['role' => 'assistant', 'content' => $finalText];
				$this->saveHistory();
				return $this->wrapOutput($finalText);
			}
		}

		return 'Error: Maximum tool call iterations reached.';
	}
}

function aido_version(): string {
	return '0.1.0';
}

function aido_home_dir(): ?string {
	$home = getenv('HOME');
	if (is_string($home) && $home !== '') {
		return rtrim($home, '/');
	}
	return null;
}

function aido_candidate_base_dirs(): array {
	$candidates = [];

	$envHome = getenv('AIDO_HOME');
	if (is_string($envHome) && $envHome !== '') {
		$candidates[] = rtrim($envHome, '/');
	}

	$cwd = getcwd();
	if (is_string($cwd) && $cwd !== '') {
		$candidates[] = rtrim($cwd, '/');
	}

	$candidates[] = __DIR__;

	$home = aido_home_dir();
	if ($home !== null) {
		$candidates[] = $home . '/.config/aido';
	}

	return array_values(array_unique($candidates));
}

function aido_find_first_existing(array $paths): ?string {
	foreach ($paths as $p) {
		if (file_exists($p)) {
			return $p;
		}
	}
	return null;
}

function aido_resolve_files(): array {
	$bases = aido_candidate_base_dirs();

	$configCandidates = [];
	$promptCandidates = [];
	$policyCandidates = [];

	foreach ($bases as $base) {
		$configCandidates[] = $base . '/config.php';
		$promptCandidates[] = $base . '/sysprompt.txt';
		$policyCandidates[] = $base . '/policy.php';
	}

	return [
		'configFile' => aido_find_first_existing($configCandidates),
		'sysPromptFile' => aido_find_first_existing($promptCandidates),
		'policyFile' => aido_find_first_existing($policyCandidates)
	];
}

function aido_policy_default(): array {
	return [
		'defaults' => [
			'model' => 'gpt-4o-mini',
			'max_tokens' => 800,
			'tool_loops' => 5,
			'history' => 'persist',
			'override_policy' => 'all'
		],
		'profiles' => [],
		'caps' => [],
		'recursion' => [
			'default_mode' => 'deny',
			'max_depth' => 0
		]
	];
}

function aido_depth(): int {
	$depth = getenv('AIDO_DEPTH');
	if (!is_string($depth) || $depth === '' || !preg_match('/^-?\d+$/', $depth)) {
		return 0;
	}
	return (int) $depth;
}

function aido_merge(array $base, array $over): array {
	foreach ($over as $k => $v) {
		if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
			$base[$k] = aido_merge($base[$k], $v);
			continue;
		}
		$base[$k] = $v;
	}
	return $base;
}

function aido_history_rank(string $mode): int {
	if ($mode === 'persist') {
		return 0;
	}
	if ($mode === 'temp') {
		return 1;
	}
	return 2;
}

function aido_apply_caps(array $effective, array $caps): array {
	if (isset($caps['max_tokens']) && is_int($caps['max_tokens'])) {
		$effective['max_tokens'] = min((int) $effective['max_tokens'], (int) $caps['max_tokens']);
	}
	if (isset($caps['tool_loops']) && is_int($caps['tool_loops'])) {
		$effective['tool_loops'] = min((int) $effective['tool_loops'], (int) $caps['tool_loops']);
	}
	if (isset($caps['history']) && is_array($caps['history'])) {
		$allowed = array_values(array_filter($caps['history'], fn($x) => is_string($x)));
		if (!in_array((string) $effective['history'], $allowed, true)) {
			$best = 'none';
			foreach ($allowed as $a) {
				if (aido_history_rank($a) > aido_history_rank($best)) {
					$best = $a;
				}
			}
			$effective['history'] = $best;
		}
	}

	return $effective;
}

function aido_effective_config(array $policy, int $depth): array {
	$effective = $policy['defaults'] ?? [];
	$profiles = $policy['profiles'] ?? [];

	if (isset($profiles[$depth]) && is_array($profiles[$depth])) {
		$effective = aido_merge($effective, $profiles[$depth]);
	}

	$effective['model'] = (string) ($effective['model'] ?? 'gpt-4o-mini');
	$effective['max_tokens'] = (int) ($effective['max_tokens'] ?? 800);
	$effective['tool_loops'] = (int) ($effective['tool_loops'] ?? 5);
	$effective['history'] = (string) ($effective['history'] ?? 'persist');
	$effective['override_policy'] = (string) ($effective['override_policy'] ?? 'all');

	$caps = $policy['caps'] ?? [];
	if (isset($caps[$depth]) && is_array($caps[$depth])) {
		$effective = aido_apply_caps($effective, $caps[$depth]);
	}

	$effective['max_tokens'] = max(1, $effective['max_tokens']);
	$effective['tool_loops'] = max(1, $effective['tool_loops']);

	return $effective;
}

function aido_history_path(string $mode): ?string {
	if ($mode === 'none') {
		return null;
	}

	$home = aido_home_dir();
	if ($mode === 'persist') {
		if ($home !== null) {
			return $home . '/.local/state/aido/conversation_history.json';
		}
		return __DIR__ . '/conversation_history.json';
	}

	$tmp = getenv('TMPDIR');
	if (!is_string($tmp) || $tmp === '') {
		$tmp = '/tmp';
	}
	$uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : '0';
	$pid = (string) getmypid();
	return rtrim($tmp, '/') . "/aido_history_{$uid}_{$pid}.json";
}

function aido_max_depth(array $policy): int {
	$rec = $policy['recursion'] ?? [];
	$maxDepth = $rec['max_depth'] ?? 0;
	return is_int($maxDepth) ? $maxDepth : (int) $maxDepth;
}

function aido_build_runtime_context(array $effective, int $depth, int $maxDepth): string {
	$time = date('c');
	$cwd = getcwd() ?: __DIR__;
	$host = php_uname('n');
	$os = php_uname('s') . ' ' . php_uname('r');

	$model = (string) ($effective['model'] ?? 'gpt-4o-mini');
	$maxTokens = (int) ($effective['max_tokens'] ?? 0);
	$toolLoops = (int) ($effective['tool_loops'] ?? 0);
	$history = (string) ($effective['history'] ?? 'persist');

	return
		"RUNTIME CONTEXT\n" .
		"- Current time: {$time}\n" .
		"- Working directory: {$cwd}\n" .
		"- Hostname: {$host}\n" .
		"- OS: {$os}\n" .
		"- Depth: {$depth}\n" .
		"- Max depth: {$maxDepth}\n" .
		"- Model: {$model}\n" .
		"- Max tokens: {$maxTokens}\n" .
		"- Tool loops: {$toolLoops}\n" .
		"- History: {$history}\n\n";
}

function aido_usage(): string {
	return
		"usage:\n" .
		"  aido \"question...\"\n" .
		"  aido [options] \"question...\"\n\n" .
		"options:\n" .
		"  -h, --help            show this help\n" .
		"  --version             show version\n" .
		"  --print-paths         show resolved file paths\n" .
		"  --print-config        show effective config\n" .
		"  --tool-loops N        override tool loop limit\n" .
		"  --max-tokens N        override max tokens\n" .
		"  --history MODE        persist|temp|none\n" .
		"  --stdin               read prompt from STDIN when no question arg is provided\n\n" .
		"env overrides:\n" .
		"  AIDO_API_MAX_RETRIES         max retries on 429 (default 6)\n" .
		"  AIDO_API_PACING_MS           optional delay before each API request (default 0)\n" .
		"  AIDO_API_BACKOFF_BASE_MS     backoff base (default 400)\n" .
		"  AIDO_API_BACKOFF_MAX_MS      backoff max (default 15000)\n\n" .
		"examples:\n" .
		"  aido \"what changed in this repo?\"\n" .
		"  echo \"hello\" | aido\n" .
		"  aido --stdin <<'EOF'\n" .
		"  build a small demo site\n" .
		"  EOF\n" .
		"  aido --print-config\n" .
		"  aido --tool-loops 15 \"do a longer task\"\n";
}

function aido_parse_args(array $argv): array {
	$opts = [
		'help' => false,
		'version' => false,
		'print_paths' => false,
		'print_config' => false,
		'tool_loops' => null,
		'max_tokens' => null,
		'history' => null,
		'stdin' => false
	];

	$args = [];
	for ($i = 1; $i < count($argv); $i++) {
		$a = (string) $argv[$i];

		if ($a === '-h' || $a === '--help') {
			$opts['help'] = true;
			continue;
		}
		if ($a === '--version') {
			$opts['version'] = true;
			continue;
		}
		if ($a === '--print-paths') {
			$opts['print_paths'] = true;
			continue;
		}
		if ($a === '--print-config') {
			$opts['print_config'] = true;
			continue;
		}
		if ($a === '--stdin') {
			$opts['stdin'] = true;
			continue;
		}
		if ($a === '--tool-loops') {
			$val = $argv[$i + 1] ?? null;
			$i++;
			$opts['tool_loops'] = is_string($val) ? (int) $val : null;
			continue;
		}
		if ($a === '--max-tokens') {
			$val = $argv[$i + 1] ?? null;
			$i++;
			$opts['max_tokens'] = is_string($val) ? (int) $val : null;
			continue;
		}
		if ($a === '--history') {
			$val = $argv[$i + 1] ?? null;
			$i++;
			$opts['history'] = is_string($val) ? (string) $val : null;
			continue;
		}

		$args[] = $a;
	}

	return [$opts, $args];
}

function aido_apply_overrides(array $effective, array $opts): array {
	$policy = (string) ($effective['override_policy'] ?? 'all');

	$applyInt = function(string $key, ?int $val) use (&$effective, $policy): void {
		if ($val === null || $val <= 0) {
			return;
		}
		if ($policy === 'none') {
			return;
		}
		if ($policy === 'safe') {
			if ($val >= (int) $effective[$key]) {
				return;
			}
		}
		$effective[$key] = $val;
	};

	$applyInt('tool_loops', is_int($opts['tool_loops']) ? $opts['tool_loops'] : null);
	$applyInt('max_tokens', is_int($opts['max_tokens']) ? $opts['max_tokens'] : null);

	$hist = $opts['history'];
	if (is_string($hist) && $hist !== '') {
		if ($policy === 'all') {
			$effective['history'] = $hist;
		} else if ($policy === 'safe') {
			$current = (string) $effective['history'];
			if (aido_history_rank($hist) >= aido_history_rank($current)) {
				$effective['history'] = $hist;
			}
		}
	}

	return $effective;
}

function aido_print_paths(array $files, int $depth): void {
	echo "depth: {$depth}\n";
	echo "config.php: " . ($files['configFile'] ?? '(not found)') . "\n";
	echo "sysprompt.txt: " . ($files['sysPromptFile'] ?? '(not found)') . "\n";
	echo "policy.php: " . ($files['policyFile'] ?? '(not found)') . "\n";
}

function aido_print_config(array $effective, ?string $historyFile, int $depth, int $maxDepth): void {
	echo "depth: {$depth}\n";
	echo "max_depth: {$maxDepth}\n";
	echo "model: " . (string) $effective['model'] . "\n";
	echo "max_tokens: " . (string) $effective['max_tokens'] . "\n";
	echo "tool_loops: " . (string) $effective['tool_loops'] . "\n";
	echo "history: " . (string) $effective['history'] . "\n";
	echo "override_policy: " . (string) $effective['override_policy'] . "\n";
	echo "history_file: " . ($historyFile ?? '(none)') . "\n";
}

function aido_is_tty_stdin(): bool {
	if (function_exists('posix_isatty')) {
		return posix_isatty(STDIN);
	}

	// Fallback: if posix is unavailable, assume non-tty to enable pipes/redirects.
	return false;
}

function aido_read_prompt_from_stdin(bool $interactiveHint = true): string {
	$stdinIsTty = aido_is_tty_stdin();

	if ($stdinIsTty && $interactiveHint) {
		fwrite(STDERR, "Enter your prompt. Finish with Ctrl-D.\n\n");
	}

	$input = stream_get_contents(STDIN);
	return trim((string) $input);
}

function aido_resolve_query(array $args, array $opts): ?string {
	$query = $args[0] ?? null;
	if (is_string($query) && trim($query) !== '') {
		return $query;
	}

	$forceStdin = (bool) ($opts['stdin'] ?? false);
	$stdinIsTty = aido_is_tty_stdin();

	if ($forceStdin || !$stdinIsTty) {
		$stdinQuery = aido_read_prompt_from_stdin($interactiveHint = $forceStdin && $stdinIsTty);
		if ($stdinQuery !== '') {
			return $stdinQuery;
		}
	}

	// Interactive mode without --stdin: still allow aido (no args) to read from tty until Ctrl-D.
	if ($stdinIsTty) {
		$stdinQuery = aido_read_prompt_from_stdin(true);
		if ($stdinQuery !== '') {
			return $stdinQuery;
		}
	}

	return null;
}

// --- CLI Execution ---

[$opts, $args] = aido_parse_args($argv);

if ($opts['help']) {
	echo aido_usage();
	exit(0);
}
if ($opts['version']) {
	echo "aido " . aido_version() . "\n";
	exit(0);
}

$files = aido_resolve_files();

if ($opts['print_paths']) {
	aido_print_paths($files, aido_depth());
	exit(0);
}

if ($files['configFile'] === null) {
	die("Error: config.php missing.\nLooked in: \$AIDO_HOME, cwd, script dir, ~/.config/aido\n");
}
if ($files['sysPromptFile'] === null) {
	die("Error: sysprompt.txt missing.\nLooked in: \$AIDO_HOME, cwd, script dir, ~/.config/aido\n");
}
if ($files['policyFile'] === null) {
	die("Error: policy.php missing.\nLooked in: \$AIDO_HOME, cwd, script dir, ~/.config/aido\n");
}

$config = require $files['configFile'];
$aiKey = $config['openai_api_key'] ?? '';
if (!is_string($aiKey) || $aiKey === '') {
	die("Error: OpenAI API Key not found in config.php\n");
}

$sysPromptText = (string) file_get_contents($files['sysPromptFile']);

$policy = require $files['policyFile'];
if (!is_array($policy)) {
	$policy = aido_policy_default();
} else {
	$policy = aido_merge(aido_policy_default(), $policy);
}

$depth = aido_depth();
$maxDepth = aido_max_depth($policy);

$effective = aido_effective_config($policy, $depth);
$effective = aido_apply_overrides($effective, $opts);

$caps = $policy['caps'] ?? [];
if (isset($caps[$depth]) && is_array($caps[$depth])) {
	$effective = aido_apply_caps($effective, $caps[$depth]);
}

$historyMode = (string) $effective['history'];
$historyFile = aido_history_path($historyMode);

if ($opts['print_config']) {
	aido_print_config($effective, $historyFile, $depth, $maxDepth);
	exit(0);
}

$query = aido_resolve_query($args, $opts);
if ($query === null || trim($query) === '') {
	echo aido_usage();
	exit(1);
}

$runtimeContext = aido_build_runtime_context($effective, $depth, $maxDepth);

$cliTool = new AiToolExecutor(
	$aiKey,
	(string) $effective['model'],
	(int) $effective['max_tokens'],
	$historyFile,
	($historyMode !== 'none'),
	$depth,
	$maxDepth
);

try {
	echo "\n\033[32m[User]:\033[0m $query\n";
	$result = $cliTool->processQuery((string) $query, $sysPromptText, $runtimeContext, (int) $effective['tool_loops']);
	echo "\033[36m[Assistant]:\033[0m $result\n\n";
} catch (Exception $e) {
	echo "\033[31mError:\033[0m " . $e->getMessage() . "\n";
}
