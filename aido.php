#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace CliTool;

use Exception;

class AiToolExecutor {
	private string $openaiApiKey;
	private array $history = [];
	private string $historyFile = __DIR__ . '/conversation_history.json';

	public function __construct(string $openaiApiKey) {
		$this->openaiApiKey = $openaiApiKey;
		$this->loadHistory();
	}

	private function loadHistory(): void {
		if (file_exists($this->historyFile)) {
			$data = json_decode(file_get_contents($this->historyFile), true);
			$this->history = is_array($data) ? $data : [];
		}
	}

	private function saveHistory(): void {
		file_put_contents($this->historyFile, json_encode($this->history, JSON_PRETTY_PRINT));
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

		$decoded = json_decode($response, true);
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

	private function wrap_output($text, $lineLength = 100) {
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

	public function processQuery(string $query): string {
		// 1) Add user question to persistent history.
		$this->history[] = ['role' => 'user', 'content' => $query];

		// 2) Temp working stack for current conversation (incl. system prompt).
		$sysprompt = file_get_contents(__DIR__ . '/sysprompt.txt');
		$runtimeContext = $this->buildRuntimeContext();

		$messages = [
			[
				'role' => 'system',
				'content' => $runtimeContext . $sysprompt
			]
		];

		// Load recent 10 history entries for context.
		$messages = array_merge($messages, array_slice($this->history, -10));

		// 3) Tool call loop (max. 5 iterations to avoid endless loops).
		for ($i = 0; $i < 5; $i++) {
			$response = $this->callOpenAiApi($messages);

			if (isset($response['error'])) {
				throw new Exception('OpenAI Error: ' . $response['error']['message']);
			}

			$message = $response['choices'][0]['message'];

			// Important: add assistant answer to working messages (text or function call).
			$messages[] = $message;

			// Check for function call.
			if (!empty($message['function_call'])) {
				$functionName = $message['function_call']['name'];
				$arguments = json_decode($message['function_call']['arguments'], true);

				if ($functionName === 'run_command' && isset($arguments['command'])) {
					$result = $this->executeCommand($arguments['command']);

					// Add tool result with role "function".
					$messages[] = [
						'role' => 'function',
						'name' => 'run_command',
						'content' => $result
					];

					// Next iteration: send history incl. tool result back to OpenAI.
					continue;
				}
			}

			// Final assistant message.
			if (isset($message['content']) && $message['content'] !== null) {
				$finalText = $message['content'];
				$this->history[] = ['role' => 'assistant', 'content' => $finalText];
				$this->saveHistory();
				return $this->wrap_output($finalText);
			}
		}

		return 'Error: Maximum tool call iterations reached.';
	}
}

// --- CLI Execution ---

$cfgfile = __DIR__ . '/config.php';
if (!file_exists($cfgfile)) {
	die("Error: config.php missing. Should return ['openai_api_key' => 'sk-...']\n");
}

$config = require $cfgfile;
$aiKey = $config['openai_api_key'] ?? '';

if (empty($aiKey)) {
	die("Error: OpenAI API Key not found in config.php\n");
}

$cliTool = new AiToolExecutor($aiKey);

$query = $argv[1] ?? null;
if (!$query) {
	echo "Usage: php aido.php \"Your question here\"\n";
	exit;
}

try {
	echo "\n\033[32m[User]:\033[0m $query\n";
	$result = $cliTool->processQuery($query);
	echo "\033[36m[Assistant]:\033[0m $result\n\n";
} catch (Exception $e) {
	echo "\033[31mError:\033[0m " . $e->getMessage() . "\n";
}
