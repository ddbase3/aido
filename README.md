# Aido - Command Line Interface Tool

## Overview
Aido is a simple, efficient command-line tool that allows users to interact with system commands via OpenAI's GPT models. The tool executes system commands based on user queries and returns results, making it an excellent assistant for managing tasks or querying system information.

Aido simplifies system-level tasks by leveraging AI, providing natural language interaction for executing shell commands like `ls`, `cat`, `grep`, and more.

## Features
- Execute system commands based on natural language queries
- Easily extendable for additional commands
- Full interaction through a simple CLI interface
- Stores conversation history for context-aware responses

## Installation

1. **Clone the repository:**
    ```bash
    git clone https://github.com/ddbase3/aido.git
    cd aido
    ```

2. **Set up the project**:
    - Create a `.env` file to store the OpenAI API key.
    - Make sure the PHP CLI is installed on your machine.

3. **Make the script executable:**
    ```bash
    sudo ln -s /path/to/aido/aido.php /usr/local/bin/aido
    sudo chmod +x /path/to/aido/aido.php
    ```

4. **Configure the API key**:
    - Copy the `config.php.example` to `config.php`:
    ```bash
    cp config.php.example config.php
    ```
    - Open the `config.php` file and insert your **OpenAI API key** into the `key` value.

5. **Run the tool**:
    ```bash
    aido "Show me the dir content"
    ```

## Usage
Once installed, you can interact with your system using simple, natural language queries. For example:

```bash
aido "List files in the current directory"
aido "What is the current working directory?"
````

Aido will execute the corresponding shell commands (`ls`, `pwd`, etc.) and provide results directly to the terminal.

## Contributing

1. Fork the repository
2. Clone your forked repository
3. Create a new branch (`git checkout -b feature-branch`)
4. Commit your changes (`git commit -m 'Add feature'`)
5. Push to your branch (`git push origin feature-branch`)
6. Create a pull request

## License

This project is licensed under the GNU General Public License v3.0 (GPL-3.0) - see the [LICENSE](LICENSE) file for details.
