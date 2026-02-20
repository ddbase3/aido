# Aido – AI-powered CLI assistant

Aido is a lightweight PHP command-line tool that lets you solve Linux tasks in natural language. It uses an OpenAI chat model and (when needed) executes system commands via a single built-in tool (`run_command`).

Aido is designed to be practical for day-to-day sysadmin/dev work:

* Ask questions in natural language
* Aido decides when to run shell commands
* It can keep conversation history for context
* It supports **policy-based limits** (tokens/tool loops/history) per **recursion depth**
* It supports standard CLI flags like `--help` and `--version`
* It supports **multi-line prompts via STDIN** (interactive and piped)

> ⚠️ **Security note**: Aido can execute shell commands. Only run it in environments you trust.

---

## Features

* **Natural language → shell actions** via `run_command`

* **Conversation history** (optional/policy-controlled)

* **Policy system** (`policy.php`) controlling:

  * model
  * max tokens
  * tool loop limit
  * history mode (persist/temp/none)
  * override policy (all/safe/none)
  * recursion max depth

* **Recursion support** (Aido can call `aido` again via `run_command`) with:

  * automatic `AIDO_DEPTH` increment
  * enforced `max_depth` from policy
  * per-depth configuration (e.g., depth 0 full power, depth 1 reduced)

* **Linux-typical CLI flags**:

  * `--help`, `--version`
  * `--print-paths`, `--print-config`
  * overrides: `--tool-loops`, `--max-tokens`, `--history`
  * input modes: `--stdin`

---

## Requirements

* PHP CLI (tested with modern PHP versions)
* `curl` extension enabled for PHP
* Linux shell environment
* An OpenAI API key

---

## Repository layout

* `aido.php` – the CLI entry point
* `sysprompt.txt` – base system prompt text (loaded at runtime)
* `policy.php` – policy configuration (defaults + profiles + caps)
* `config.php.example` – example config file containing the API key

Local-only files (ignored by git):

* `config.php` – your API key (secret)
* `conversation_history.json` – local repo state (optional legacy location)

Runtime state (recommended locations):

* `~/.config/aido/` – user configuration (config/sysprompt/policy)
* `~/.local/state/aido/` – persistent history

---

## Installation

Aido is meant to be installed as a simple executable in `/usr/local/bin` and configured in your home directory.

### 1) Clone the repository

```bash
git clone https://github.com/ddbase3/aido.git
cd aido
```

### 2) Install the executable

This copies the script into your PATH (copy-install, not a symlink):

```bash
sudo install -m 755 aido.php /usr/local/bin/aido
```

Verify:

```bash
which aido
aido --version
```

### 3) Create your user config directory

```bash
mkdir -p ~/.config/aido
```

### 4) Configure your API key (`config.php`)

Copy the example config and add your API key:

```bash
cp -n config.php.example ~/.config/aido/config.php
chmod 600 ~/.config/aido/config.php
```

Edit the file and set:

* `openai_api_key` → your key

### 5) Install `sysprompt.txt` and `policy.php`

```bash
cp -f sysprompt.txt ~/.config/aido/sysprompt.txt
cp -f policy.php ~/.config/aido/policy.php
```

### 6) (Optional) Copy existing history

If you have a history file in the repo (legacy), you can move/copy it to the recommended state directory:

```bash
mkdir -p ~/.local/state/aido
if [ -f conversation_history.json ]; then
	cp -f conversation_history.json ~/.local/state/aido/conversation_history.json
fi
```

---

## Quick start

```bash
aido "what changed in this git repository?"
aido "find large files in /var/log and summarize"
```

---

## Multi-line prompts (STDIN)

Aido can read the prompt from **STDIN**. This is useful for:

* multi-line prompts
* piping text from other tools
* keeping long instructions out of shell quotes

### Pipe input

```bash
echo "summarize the changes in this repo" | aido
```

### Redirect from a file

```bash
aido < prompt.txt
```

### Here-doc

```bash
aido --stdin <<'EOF'
Create a small demo website with 3 pages.
Use a clean responsive layout.
Download placeholder images and reference them locally.
EOF
```

### Interactive entry (finish with Ctrl-D)

```bash
aido
# type your prompt (multiple lines)
# finish input with Ctrl-D (EOF)
```

Notes:

* Use **Ctrl-D** (EOF) to end input. Ctrl-C typically aborts the program.
* If you provide a normal quoted argument (`aido "..."`), STDIN is ignored.

---

## How Aido finds its files

Aido resolves `config.php`, `sysprompt.txt`, and `policy.php` by searching in this order:

1. `$AIDO_HOME/` (if set)
2. current working directory (`getcwd()`)
3. script directory (`__DIR__`, e.g. `/usr/local/bin`)
4. `~/.config/aido/`

This allows:

* working inside a repo (cwd-based files)
* global usage with `~/.config/aido/` (recommended)

### Debug paths

```bash
aido --print-paths
```

---

## CLI usage

### Help

```bash
aido --help
```

### Version

```bash
aido --version
```

### Show effective config (after policy + depth + caps)

```bash
aido --print-config
```

### Override limits (if allowed by policy)

```bash
aido --tool-loops 15 "do a longer multi-step task"
aido --max-tokens 900 "write a more detailed explanation"
aido --history none "answer without storing history"
```

Overrides are controlled by your policy’s `override_policy` and `caps` for the current depth.

---

## Policy system (`policy.php`)

Aido uses `policy.php` to decide what it’s allowed to do. The policy supports:

* `defaults` – base values
* `profiles[depth]` – per-depth defaults (e.g., depth 0 = full power, depth 1 = subtask)
* `caps[depth]` – hard limits per depth (cannot be exceeded)
* `recursion.max_depth` – maximum recursion depth allowed

### Example behavior (typical)

* **Depth 0** (normal interactive use)

  * higher tokens/tool loops
  * history persisted
  * overrides allowed

* **Depth 1** (subtask / recursive call)

  * reduced tokens/tool loops
  * history temp or none
  * overrides restricted (`safe`)

* **Depth 2+**

  * usually locked down

---

## Recursion model and safety

Aido can recursively call itself through `run_command`, e.g.:

```bash
aido 'run this exact command using run_command: aido --print-config'
```

When Aido detects a self-call (`aido ...` or `/usr/local/bin/aido ...`), it automatically:

* increments `AIDO_DEPTH` (`AIDO_DEPTH=1`, `AIDO_DEPTH=2`, ...)
* enforces `recursion.max_depth` from `policy.php`

This means recursion behavior is policy-driven:

* per-depth profiles apply automatically
* recursion stops safely at `max_depth`

---

## Runtime context

At runtime, Aido prepends context to the system prompt (before `sysprompt.txt`) including:

* current time (ISO 8601)
* current working directory
* hostname
* OS info
* depth + max depth
* effective limits (model/max tokens/tool loops/history)

This helps the assistant make realistic plans within the configured limits.

---

## Configuration files

### `~/.config/aido/config.php`

Contains your API key. Example:

```php
<?php

return [
	'openai_api_key' => 'sk-...'
];
```

### `~/.config/aido/sysprompt.txt`

Defines the assistant behavior rules. Aido will prepend runtime context automatically.

### `~/.config/aido/policy.php`

Defines limits and behavior per depth.

---

## Git hygiene

The repo includes a `.gitignore` that ignores local secrets and state:

* `config.php`
* `conversation_history.json`

Recommended practice:

* keep secrets in `~/.config/aido/`
* keep state in `~/.local/state/aido/`

---

## License

GPL-3.0 — see `LICENSE`.
