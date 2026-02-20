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

	public function __construct(string $openaiApiKey, string $model, int $maxTokens, ?string $historyFile, bool $historyEnabled, int $depth, int $maxDepth) {
		$this->openaiApiKey = $openaiApiKey;
		$this->model = $model;
		$this->maxTokens = $maxTokens;
		$this->historyFile = $historyFile;
		$this->historyEnabled = $historyEnabled;
		$this->depth = $depth;
		$this->maxDepth = $maxDepth;

		$this->loadHistory();
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
				]
			],
			'max_tokens' => $this->maxTokens
		];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->openaiApiKey
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			throw new Exception('cURL Error: ' . curl_error($ch));
		}
		curl_close($ch);

		$decoded = json_decode((string) $response, true);
		if (!$decoded) {
			throw new Exception('Invalid API Response: ' . $response);
		}

		return $decoded;
	}

	private function isSelfCall(string $command): bool {
		$cmd = ltrim($command);

		if (preg_match('/\bAIDO_DEPTH=/', $cmd)) {
			return false;
		}

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

		// Keep recursion behavior purely policy-driven:
		// we only propagate AIDO_DEPTH so the configured profile for that depth applies.
		return "AIDO_DEPTH={$nextDepth} " . $command;
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
