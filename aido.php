#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace CliTool;

use Exception;

class AiToolExecutor {
	private string $openaiApiKey;
	private array $history = [];
	private string $historyFile;

	public function __construct(string $openaiApiKey, string $historyFile) {
		$this->openaiApiKey = $openaiApiKey;
		$this->historyFile = $historyFile;
		$this->loadHistory();
	}

	private function loadHistory(): void {
		if (!file_exists($this->historyFile)) {
			$this->history = [];
			return;
		}

		$data = json_decode((string) file_get_contents($this->historyFile), true);
		$this->history = is_array($data) ? $data : [];
	}

	private function saveHistory(): void {
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
			'model' => 'gpt-4o-mini',
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
			'max_tokens' => 500
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

	private function executeCommand(string $command): string {
		echo "\033[33m[Executing]: $command\033[0m\n";
		$output = shell_exec($command . ' 2>&1');

		if ($output === null || $output === false) {
			return 'Command executed, but returned no output or failed.';
		}

		return $output;
	}

	private function wrapOutput(string $text, int $lineLength = 100): string {
		// Wrap text to lines containing max. $lineLength chars.
		return wordwrap($text, $lineLength, "\n", true);
	}

	private function buildRuntimeContext(): string {
		$time = date('c');
		$cwd = getcwd() ?: __DIR__;
		$host = php_uname('n');
		$os = php_uname('s') . ' ' . php_uname('r');

		return
			"RUNTIME CONTEXT\n" .
			"- Current time: {$time}\n" .
			"- Working directory: {$cwd}\n" .
			"- Hostname: {$host}\n" .
			"- OS: {$os}\n\n";
	}

	public function processQuery(string $query, string $sysPromptText): string {
		// Add user question to persistent history.
		$this->history[] = ['role' => 'user', 'content' => $query];

		// Build working message stack for this request (system prompt + recent history).
		$runtimeContext = $this->buildRuntimeContext();

		$messages = [
			[
				'role' => 'system',
				'content' => $runtimeContext . $sysPromptText
			]
		];

		// Load the last 10 history entries for context.
		$messages = array_merge($messages, array_slice($this->history, -10));

		// Tool call loop (max. 5 iterations to avoid endless loops).
		for ($i = 0; $i < 5; $i++) {
			$response = $this->callOpenAiApi($messages);

			if (isset($response['error'])) {
				throw new Exception('OpenAI Error: ' . ($response['error']['message'] ?? 'Unknown error'));
			}

			$message = $response['choices'][0]['message'] ?? null;
			if (!is_array($message)) {
				throw new Exception('Invalid OpenAI response structure.');
			}

			// Always add assistant message to the working conversation.
			$messages[] = $message;

			// Check for function call.
			if (!empty($message['function_call'])) {
				$functionName = (string) ($message['function_call']['name'] ?? '');
				$argsJson = (string) ($message['function_call']['arguments'] ?? '{}');
				$arguments = json_decode($argsJson, true);
				$arguments = is_array($arguments) ? $arguments : [];

				if ($functionName === 'run_command' && isset($arguments['command'])) {
					$result = $this->executeCommand((string) $arguments['command']);

					// Add tool result with role "function".
					$messages[] = [
						'role' => 'function',
						'name' => 'run_command',
						'content' => $result
					];

					// Next iteration: send updated messages including tool result back to OpenAI.
					continue;
				}
			}

			// Final assistant message.
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
	foreach ($bases as $base) {
		$configCandidates[] = $base . '/config.php';
		$promptCandidates[] = $base . '/sysprompt.txt';
	}

	$configFile = aido_find_first_existing($configCandidates);
	$sysPromptFile = aido_find_first_existing($promptCandidates);

	$home = aido_home_dir();
	$historyFile = null;

	if ($home !== null) {
		$historyFile = $home . '/.local/state/aido/conversation_history.json';
	} else {
		// Fallback: keep history next to script if HOME is not available.
		$historyFile = __DIR__ . '/conversation_history.json';
	}

	return [
		'configFile' => $configFile,
		'sysPromptFile' => $sysPromptFile,
		'historyFile' => $historyFile
	];
}

// --- CLI Execution ---

$files = aido_resolve_files();

if ($files['configFile'] === null) {
	die("Error: config.php missing.\nLooked in: \$AIDO_HOME, cwd, script dir, ~/.config/aido\n");
}
if ($files['sysPromptFile'] === null) {
	die("Error: sysprompt.txt missing.\nLooked in: \$AIDO_HOME, cwd, script dir, ~/.config/aido\n");
}

$config = require $files['configFile'];
$aiKey = $config['openai_api_key'] ?? '';

if (!is_string($aiKey) || $aiKey === '') {
	die("Error: OpenAI API Key not found in config.php\n");
}

$sysPromptText = (string) file_get_contents($files['sysPromptFile']);

$cliTool = new AiToolExecutor($aiKey, (string) $files['historyFile']);

$query = $argv[1] ?? null;
if (!$query) {
	echo "Usage: aido \"Your question here\"\n";
	exit(1);
}

try {
	echo "\n\033[32m[User]:\033[0m $query\n";
	$result = $cliTool->processQuery((string) $query, $sysPromptText);
	echo "\033[36m[Assistant]:\033[0m $result\n\n";
} catch (Exception $e) {
	echo "\033[31mError:\033[0m " . $e->getMessage() . "\n";
}
