<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Api;

use SilverStripe\SiteConfig\SiteConfig;
use Exception;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use Sunnysideup\AiLlmConnector\Api\Connectors\TestConnector;

/**
 * Base class for all LLM / AI connectors.
 *
 * Quick start:
 *
 *     use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;
 *
 *     $answer = ConnectorBaseClass::inst()->askQuestion('Say hi');
 *     // or, shorter:
 *     $answer = ConnectorBaseClass::ask('Say hi');
 *
 * `inst()` returns the connector selected through (in order of preference)
 * the environment (SS_LLM_CLIENT_NAME), the YML config (client_name) and,
 * as a last resort, the harmless `TestConnector`.
 *
 * To add a new LLM, extend this class, set `$shortName` and `$defaultModel`,
 * and implement `makeClient()` and `askQuestion()`.
 * See docs/en/INDEX.md for full documentation.
 */
abstract class ConnectorBaseClass
{
    use Configurable;
    use Injectable;

    /** Short name (e.g. OpenAI) or class name of the connector to use. */
    private static string $client_name = '';

    /** Model to use for all connectors. Also available per connector as client_model_{shortname}. */
    private static string $client_model = '';

    /** Timeout for API calls (honoured by connectors that support it). */
    private static int $time_out_in_seconds = 90;

    /** Default temperature (creativity) between 0 and 1. */
    private static float $temperature = 0.7;

    /** Maximum tokens in the answer, 0 = connector default. */
    private static int $max_tokens = 0;

    protected string $defaultModel;

    protected string $shortName;

    protected $client;

    protected float $currentTemperature = 0.7;

    /**
     * Set the temperature (creativity) for the next questions: 0 - 1.
     * Invalid values fall back to the configured default.
     */
    public function setTemperature(float $temperature): static
    {
        $this->currentTemperature = $this->standardiseTemperature($temperature);
        return $this;
    }

    public function getTemperature(): float
    {
        return $this->standardiseTemperature($this->currentTemperature);
    }

    protected function standardiseTemperature(float $temperature): float
    {
        if (!$temperature || $temperature === 0 || $temperature < 0 || $temperature > 1) {
            $temperature = $this->config()->get('temperature') ?: 0.7;
        }

        return $temperature;
    }

    public function __construct()
    {
        $this->makeClient();
    }

    abstract protected function makeClient(): void;

    /** Short name of this connector, e.g. OpenAI. Used in env variable names and dropdowns. */
    public function getShortName(): string
    {
        if ($this->shortName === '' || $this->shortName === '0') {
            $this->shortName = ClassInfo::shortName($this);
        }

        return $this->shortName;
    }

    /** Model used when nothing else has been set. */
    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Is an API key and model available? Throws an Exception if the key or SDK is missing,
     * use `is_configured()` for a version that does not throw.
     */
    public static function is_ready(?string $client = null): bool
    {
        $obj = static::inst($client);
        return $obj->IsReady();
    }

    /**
     * @param string|null $client
     * @throws Exception
     * @return static
     */
    public static function inst(?string $client = null)
    {
        $client = static::get_client_name($client);
        return Injector::inst()->get($client);
    }

    public function IsReady(): bool
    {
        $methodsToReturn = [
            'getApiKey',
            'getModel',
        ];
        foreach ($methodsToReturn as $method) {
            if (!$this->$method()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is a SiteConfig value (LLMClient, LLMModel or LLMKey) overruled by
     * the environment or the YML config? This mirrors the precedence
     * actually applied in get_client_name(), getApiKey() and getModel().
     *
     * Currently only the client can be overruled: SS_LLM_CLIENT_NAME or YML `client_name`
     * always win over the SiteConfig value.  The key and model set in the SiteConfig
     * take precedence over the environment, so they are never overruled.
     *
     * @param string $field LLMClient|LLMModel|LLMKey
     * @return string description of what overrules the field, empty string if nothing does
     */
    public static function get_overruled_by(string $field): string
    {
        if ($field === 'LLMClient') {
            if (Environment::getEnv('SS_LLM_CLIENT_NAME')) {
                return 'the environment variable SS_LLM_CLIENT_NAME';
            }

            if (Config::inst()->get(self::class, 'client_name')) {
                return 'the YML setting client_name';
            }
        }

        return '';
    }

    /**
     *
     * this method is static to help the static method inst
     * @param mixed $client
     * @return string
     */
    protected static function get_client_name(?string $client = null): string
    {
        if (! $client) {
            $client = SiteConfig::current_site_config()->LLMClient;
            if (! $client) {
                $client = Environment::getEnv('SS_LLM_CLIENT_NAME');
                if (! $client) {
                    $client = Config::inst()->get(self::class, 'client_name');
                }
            }

            $client = (string) (
                Environment::getEnv('SS_LLM_CLIENT_NAME') ?:
                Config::inst()->get(self::class, 'client_name')
            );
        }

        $isValidClient = self::get_client_is_valid($client);
        if (! $isValidClient) {
            $classes = ClassInfo::subclassesFor(self::class, false);
            foreach ($classes as $class) {
                if (Injector::inst()->get($class)->getShortName() === $client) {
                    $client = $class;
                    break;
                }
            }
        }

        if (!self::get_client_is_valid($client)) {
            $client = TestConnector::class;
        }

        return $client;
    }

    protected static function get_client_is_valid(string $client): bool
    {
        return class_exists($client) && is_subclass_of($client, static::class);
    }

    /**
     * Run a query against the configured LLM
     */
    abstract public function askQuestion(string $query, ?string $model = ''): string;


    /**
     * API key, in order of preference: SiteConfig, SS_LLM_CLIENT_API_KEY,
     * SS_LLM_CLIENT_API_KEY_{SHORTNAME}.
     *
     * @throws Exception if no key can be found
     */
    protected function getApiKey(): ?string
    {
        $v = SiteConfig::current_site_config()->LLMKey;
        if (! $v) {
            $v = Environment::getEnv('SS_LLM_CLIENT_API_KEY');
            if (! $v) {
                // e.g. SS_LLM_CLIENT_API_KEY_OPENAI
                $myVarName = 'SS_LLM_CLIENT_API_KEY_' . strtoupper($this->getShortName());
                $v = Environment::getEnv($myVarName);
                if (! $v) {
                    throw new Exception(
                        'The LLM Api key (using SS_LLM_CLIENT_API_KEY or ' . $myVarName . ')  is not configured in this environment.'
                    );
                }
            }
        }

        return $v ?: null;
    }

    /**
     * Model, in order of preference: argument, SiteConfig, SS_LLM_CLIENT_MODEL,
     * SS_LLM_CLIENT_MODEL_{SHORTNAME}, YML client_model, YML client_model_{shortname}, default model.
     */
    protected function getModel(?string $model = ''): ?string
    {
        $v = $model;
        if (! $v) {
            $v = SiteConfig::current_site_config()->LLMModel;
            if (! $v) {
                $v = Environment::getEnv('SS_LLM_CLIENT_MODEL');
                if (!$v && ! $v) {
                    $v = Environment::getEnv('SS_LLM_CLIENT_MODEL_' . strtoupper($this->getShortName()))
                        ?: Environment::getEnv('SS_LLM_CLIENT_MODEL_' . $this->getShortName());
                    if (! $v) {
                        $v = Config::inst()->get(static::class, 'client_model');
                        if (! $v) {
                            $v = Config::inst()->get(static::class, 'client_model_' . strtolower($this->getShortName()));
                            if (! $v) {
                                $v = $this->getDefaultModel();
                            }
                        }
                    }
                }
            }
        }

        return $v ?: null;
    }

    public function getClientNameNice(): string
    {
        return $this->getShortName();
    }

    public function getModelNice(): string
    {
        return $this->getModel();
    }

    public function getApiKeyNice(): string
    {
        $input = $this->getApiKey();
        $len = strlen((string) $input);
        if ($len <= 6) {
            return 'No valid key set';
        }

        $start = substr((string) $input, 0, 5);
        $end = substr((string) $input, -5);

        return $start . '********' . $end;
    }

    /**
     * Returns [shortName => niceName] for every available connector.
     * Used for dropdowns.
     */
    public function getClientNameList(): array
    {
        $list = [];
        $classes = ClassInfo::subclassesFor(self::class, false);
        foreach ($classes as $class) {
            try {
                $obj = Injector::inst()->get($class);
                $list[$obj->getShortName()] = $obj->getClientNameNice();
            } catch (Exception $exception) {
                // SDK missing or API key not set: still list the connector by its class name
                $list[ClassInfo::shortName($class)] = ClassInfo::shortName($class);
            }
        }

        return $list;
    }

    /**
     * Shortcut: ask the configured LLM a question in one line.
     *
     * ```php
     * $answer = ConnectorBaseClass::ask('Summarise this text: ...');
     * $answer = ConnectorBaseClass::ask('...', 'gpt-4o', 'OpenAI');
     * ```
     *
     * @param string|null $client short name (e.g. "OpenAI") or class name; null = use configured client
     */
    public static function ask(string $question, ?string $model = '', ?string $client = null): string
    {
        return static::inst($client)->askQuestion($question, $model);
    }

    /**
     * Ask a question and decode the answer as JSON.
     * Markdown code fences (```json ... ```) around the answer are removed.
     * You should instruct the LLM in your question to answer with JSON only.
     *
     * @throws Exception if the answer is not valid JSON
     */
    public function askQuestionAsJson(string $question, ?string $model = ''): array
    {
        $answer = trim($this->askQuestion($question, $model));
        $answer = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $answer);
        $data = json_decode(trim($answer), true);
        if (!is_array($data)) {
            throw new Exception('The LLM did not return valid JSON: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Does the connector have everything it needs (SDK, API key, model)?
     * Unlike `is_ready()` this never throws, it simply returns false.
     *
     * @param string|null $client short name or class name; null = configured client
     */
    public static function is_configured(?string $client = null): bool
    {
        try {
            return static::is_ready($client);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns a human readable reason why the connector is not working,
     * or an empty string if all is fine.
     */
    public static function get_problem(?string $client = null): string
    {
        try {
            return static::is_ready($client) ? '' : 'No API key or model has been set.';
        } catch (\Throwable $throwable) {
            return $throwable->getMessage();
        }
    }

    /**
     * URL of the build task that tests the connection.
     */
    public function getTestLink(): string
    {
        return '/dev/tasks/acm-test-llm';
    }

    /** Timeout in seconds for API calls. */
    public function getTimeout(): int
    {
        return $this->config()->get('time_out_in_seconds') ?: 90;
    }


    /**
     * Maximum number of tokens in the answer (0 = not limited / connector default).
     */
    public function getMaxTokens(): float
    {
        return $this->config()->get('max_tokens') ?: 0;
    }
}
