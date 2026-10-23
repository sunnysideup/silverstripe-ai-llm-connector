# AI / LLM Connector

A thin, provider-independent layer for asking questions to Large Language Models (LLMs).
It is used by [sunnysideup/automated-content-management](https://github.com/sunnysideup/silverstripe-automated-content-management)
but has no dependency on it, so any module can use it.

## Contents

1. [Installation](#installation)
2. [Quick start](#quick-start)
3. [Configuration](#configuration)
4. [Available connectors](#available-connectors)
5. [API reference](#api-reference)
6. [Error handling](#error-handling)
7. [Writing your own connector](#writing-your-own-connector)
8. [CMS settings (SiteConfig)](#cms-settings-siteconfig)
9. [Testing your setup](#testing-your-setup)
10. [Migrating from automated-content-management](#migrating-from-automated-content-management)

## Installation

```shell
composer require sunnysideup/ai-llm-connector
```

The provider SDKs are **optional**. Install only the one(s) you need:

| Connector | Short name | Composer package             |
| --------- | ---------- | ---------------------------- |
| OpenAI    | `OpenAI`   | `openai-php/client` (^0.21)  |
| Claude    | `Claude`   | `mozex/anthropic-php` (^1.8) |
| Gemini    | `Gemini`   | `google-gemini-php/client` (^2.0) |

If an SDK is missing, the connector throws an `Exception` with the exact `composer require` command
instead of causing a fatal PHP error.

## Quick start

```php
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

// 1. one-liner, uses whatever LLM is configured
$answer = ConnectorBaseClass::ask('Rewrite this to be friendlier: ' . $text);

// 2. choose model and / or client explicitly
$answer = ConnectorBaseClass::ask('Hello', 'gpt-4o', 'OpenAI');

// 3. get the connector object for more control
$connector = ConnectorBaseClass::inst();          // configured client
$connector = ConnectorBaseClass::inst('Gemini');  // by short name
$connector->setTemperature(0.2);                  // 0 - 1, lower = more predictable
$answer = $connector->askQuestion('Hello');

// 4. structured answers
$data = $connector->askQuestionAsJson('List 3 colours. Answer with a JSON array only.');
```

Check before you call:

```php
if (ConnectorBaseClass::is_configured()) {
    // SDK installed, API key and model available
} else {
    $reason = ConnectorBaseClass::get_problem(); // human readable
}
```

## Configuration

### Environment (`.env`)

```shell
# Which LLM to use: OpenAI, Claude, Gemini (short name) or a full class name
SS_LLM_CLIENT_NAME="OpenAI"

# API key for whatever LLM is selected
SS_LLM_CLIENT_API_KEY="your-api-key-here"

# ... or one key per LLM (upper case short name at the end)
SS_LLM_CLIENT_API_KEY_OPENAI="..."
SS_LLM_CLIENT_API_KEY_CLAUDE="..."
SS_LLM_CLIENT_API_KEY_GEMINI="..."

# Optional: model (each connector has a default)
SS_LLM_CLIENT_MODEL="gpt-4o"
# ... or per LLM
SS_LLM_CLIENT_MODEL_OPENAI="gpt-4o"
SS_LLM_CLIENT_MODEL_CLAUDE="claude-3-opus-20240229"
SS_LLM_CLIENT_MODEL_GEMINI="gemini-2.5-flash"
```

### YML

```yml
Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass:
  client_name: OpenAI # or Sunnysideup\AiLlmConnector\Api\Connectors\OpenAIConnector
  client_model: gpt-4o
  client_model_openai: gpt-4o
  client_model_claude: claude-3-opus-20240229
  client_model_gemini: gemini-2.5-flash
  time_out_in_seconds: 90
  temperature: 0.7
  max_tokens: 0 # 0 = connector default
```

### Order of precedence

**API key:** SiteConfig `LLMKey` > `SS_LLM_CLIENT_API_KEY` > `SS_LLM_CLIENT_API_KEY_{SHORTNAME}`

**Model:** method argument > SiteConfig `LLMModel` > `SS_LLM_CLIENT_MODEL` > `SS_LLM_CLIENT_MODEL_{SHORTNAME}` >
YML `client_model` > YML `client_model_{shortname}` > connector default

**Client:** argument to `inst()` / `ask()` > `SS_LLM_CLIENT_NAME` > YML `client_name` > `TestConnector`.

> Note: when no client is passed in, the `SS_LLM_CLIENT_NAME` / `client_name` settings are used.
> The SiteConfig `LLMClient` value is currently not consulted by `inst()`.

If no valid client can be found, the `TestConnector` is used. It never calls an API
and always returns a fixed sentence, which makes it safe for development.

## Available connectors

| Class                                                         | Short name | Default model              |
| ------------------------------------------------------------- | ---------- | -------------------------- |
| `Sunnysideup\AiLlmConnector\Api\Connectors\OpenAIConnector`   | OpenAI     | `gpt-4o`                   |
| `Sunnysideup\AiLlmConnector\Api\Connectors\ClaudeConnector`   | Claude     | `claude-3-opus-20240229`   |
| `Sunnysideup\AiLlmConnector\Api\Connectors\GeminiConnector`   | Gemini     | `gemini-2.5-flash`         |
| `Sunnysideup\AiLlmConnector\Api\Connectors\TestConnector`     | Test       | `test-model`               |

Every subclass of `ConnectorBaseClass` is found automatically (via `ClassInfo::subclassesFor()`)
and appears in `getClientNameList()`.

## API reference

All classes live in `Sunnysideup\AiLlmConnector\Api`.

### Static methods on `ConnectorBaseClass`

| Method                                                    | Description |
| --------------------------------------------------------- | ----------- |
| `inst(?string $client = null)`                            | Connector object. `$client` is a short name or class name. |
| `ask(string $question, ?string $model = '', ?string $client = null)` | Ask a question in one call, returns the answer text. |
| `is_ready(?string $client = null)`                        | `true` if API key and model are available. Throws if SDK / key missing. |
| `is_configured(?string $client = null)`                   | Same as `is_ready()` but never throws. |
| `get_problem(?string $client = null)`                     | Human readable reason why it is not working, `''` if fine. |

### Instance methods

| Method                                        | Description |
| --------------------------------------------- | ----------- |
| `askQuestion(string $question, ?string $model = '')` | Returns the answer as text. |
| `askQuestionAsJson(string $question, ?string $model = '')` | Returns the answer decoded as array. Strips markdown code fences. Throws if not valid JSON. |
| `setTemperature(float $t)` / `getTemperature()` | Creativity 0 - 1. Invalid values fall back to config. |
| `getShortName()`                              | e.g. `OpenAI`. |
| `getDefaultModel()`                           | Model used when nothing else is set. |
| `getClientNameNice()` / `getModelNice()` / `getApiKeyNice()` | Display values (key is masked). |
| `getClientNameList()`                         | `[shortName => name]` of all connectors, handy for dropdowns. |
| `getTimeout()` / `getMaxTokens()`             | Configured values. |
| `getTestLink()`                               | URL of the test task. |
| `IsReady()`                                   | Instance version of `is_ready()`. |

## Error handling

| Situation                  | What happens |
| -------------------------- | ------------ |
| SDK not installed          | `Exception`: "... Please run: composer require ..." |
| API key missing            | `Exception` naming the env variables you can set. |
| Provider error / timeout   | Logged with `error_log` and re-thrown (OpenAI retries 3 times on timeouts). |

```php
try {
    $answer = ConnectorBaseClass::ask($question);
} catch (\Exception $e) {
    // show $e->getMessage() to the developer, use fallback content for the user
}
```

## Writing your own connector

```php
namespace MyProject;

use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

class MistralConnector extends ConnectorBaseClass
{
    protected string $shortName = 'Mistral';

    protected string $defaultModel = 'mistral-large-latest';

    protected function makeClient(): void
    {
        if (!class_exists(\Some\Mistral\Client::class)) {
            throw new \Exception('Mistral SDK is not installed. Please run: composer require some/mistral');
        }
        $this->client = new \Some\Mistral\Client($this->getApiKey());
    }

    public function askQuestion(string $question, ?string $model = ''): string
    {
        return $this->client->chat($this->getModel($model), $question);
    }
}
```

Then run `dev/build?flush=1` and use it with `SS_LLM_CLIENT_NAME="Mistral"`.
Helpers available to subclasses: `getApiKey()`, `getModel()`, `getTemperature()`, `getMaxTokens()`, `getTimeout()`.

## CMS settings (SiteConfig)

The module adds three fields to `SiteConfig` (tab **LLM**): `LLMClient`, `LLMModel` and `LLMKey`.
`LLMKey` and `LLMModel` take precedence over environment settings.
A link to the test task is shown on the same tab.

A field is shown as **read-only** (with an explanation) if its value is overruled by the environment or YML config.
Right now that applies to `LLMClient` when `SS_LLM_CLIENT_NAME` or the YML `client_name` is set.
`LLMModel` and `LLMKey` are not overruled (SiteConfig wins), so they stay editable.
Use `ConnectorBaseClass::get_overruled_by('LLMClient')` to check this in your own code
(returns a description of what overrules the field, or `''`).

## Testing your setup

Run the build task `dev/tasks/acm-test-llm` (title "Test LLM"). Add `?question=...` to use your own question.
From the command line:

```shell
vendor/bin/sake dev/tasks/acm-test-llm
```

## Migrating from automated-content-management

The classes moved namespace. Update any references:

| Old                                                                 | New |
| ------------------------------------------------------------------- | --- |
| `Sunnysideup\AutomatedContentManagement\Api\ConnectorBaseClass`     | `Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass` |
| `Sunnysideup\AutomatedContentManagement\Api\Connectors\*`           | `Sunnysideup\AiLlmConnector\Api\Connectors\*` |
| `Sunnysideup\AutomatedContentManagement\Tasks\TestLLM`              | `Sunnysideup\AiLlmConnector\Tasks\TestLLM` |

This includes YML config keys (e.g. `time_out_in_seconds`, `temperature`).
Environment variables, SiteConfig fields (`LLMClient`, `LLMModel`, `LLMKey`) and the task segment
(`acm-test-llm`) are unchanged, so no database changes are needed.
