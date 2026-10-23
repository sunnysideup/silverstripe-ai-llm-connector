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
 * the environment (SS_LLM_CLIENT_NAME), the YML config (client_name), the SiteConfig
 * (Settings > LLM) and, as a last resort, the harmless `TestConnector`.
 * The same order (environment, YML, SiteConfig, default) applies to model and API key.
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
     * Where does the effective value of a SiteConfig field (LLMClient, LLMModel or LLMKey) come from?
     *
     * Precedence (highest first): environment, YML config, SiteConfig (database), default.
     * Returns a description of the environment / YML source if it overrules the SiteConfig,
     * or an empty string if the SiteConfig value is the one in use (or nothing is set).
     *
     * @param string $field LLMClient|LLMModel|LLMKey
     */
    public static function get_overruled_by(string $field): string
    {
        try {
            if ($field === 'LLMClient') {
                $source = self::lookup_client()[1];
            } else {
                $source = self::inst()->lookupSetting($field)[1];
            }
        } catch (\Throwable) {
            return '';
        }

        return $source === 'the SiteConfig' ? '' : $source;
    }

    /**
     * Client setting: [value, source].
     * Order: SS_LLM_CLIENT_NAME, YML client_name, SiteConfig LLMClient.
     *
     * @return array{0: string, 1: string}
     */
    protected static function lookup_client(): array
    {
        $v = Environment::getEnv('SS_LLM_CLIENT_NAME');
        if ($v) {
            return [(string) $v, 'the environment variable SS_LLM_CLIENT_NAME'];
        }

        $v = Config::inst()->get(self::class, 'client_name');
        if ($v) {
            return [(string) $v, 'the YML setting client_name'];
        }

        $v = SiteConfig::current_site_config()->LLMClient;
        if ($v) {
            return [(string) $v, 'the SiteConfig'];
        }

        return ['', ''];
    }

    /**
     * Model or key setting for this connector: [value, source].
     * Order: environment, YML, SiteConfig.  Within each layer the connector specific
     * setting (e.g. SS_LLM_CLIENT_MODEL_OPENAI) beats the generic one.
     *
     * @param string $field LLMModel|LLMKey
     * @return array{0: string, 1: string}
     */
    protected function lookupSetting(string $field): array
    {
        $short = $this->getShortName();
        if ($field === 'LLMKey') {
            $envNames = ['SS_LLM_CLIENT_API_KEY_' . strtoupper($short), 'SS_LLM_CLIENT_API_KEY'];
            $ymlNames = [];
        } else {
            $envNames = ['SS_LLM_CLIENT_MODEL_' . strtoupper($short), 'SS_LLM_CLIENT_MODEL'];
            $ymlNames = ['client_model_' . strtolower($short), 'client_model'];
        }

        foreach ($envNames as $name) {
            $v = Environment::getEnv($name);
            if ($v) {
                return [(string) $v, 'the environment variable ' . $name];
            }
        }

        foreach ($ymlNames as $name) {
            $v = Config::inst()->get(static::class, $name);
            if ($v) {
                return [(string) $v, 'the YML setting ' . $name];
            }
        }

        $v = SiteConfig::current_site_config()->$field;
        if ($v) {
            return [(string) $v, 'the SiteConfig'];
        }

        return ['', ''];
    }

    /**
     * this method is static to help the static method inst
     */
    protected static function get_client_name(?string $client = null): string
    {
        if (! $client) {
            $client = self::lookup_client()[0];
        }

        if (! self::get_client_is_valid($client)) {
            // maybe it is a short name (e.g. OpenAI)
            foreach (ClassInfo::subclassesFor(self::class, false) as $class) {
                try {
                    if (Injector::inst()->get($class)->getShortName() === $client) {
                        $client = $class;
                        break;
                    }
                } catch (\Throwable) {
                    // SDK missing or similar: skip this connector
                }
            }
        }

        if (! self::get_client_is_valid($client)) {
            $client = TestConnector::class;
        }

        return $client;
    }

    protected static function get_client_is_valid(string $client): bool
    {
        return $client !== '' && class_exists($client) && is_subclass_of($client, self::class);
    }

    /**
     * Run a query against the configured LLM
     */
    abstract public function askQuestion(string $query, ?string $model = ''): string;


    /**
     * API key, in order of preference: SS_LLM_CLIENT_API_KEY_{SHORTNAME}, SS_LLM_CLIENT_API_KEY,
     * SiteConfig.
     *
     * @throws Exception if no key can be found
     */
    protected function getApiKey(): ?string
    {
        $v = $this->lookupSetting('LLMKey')[0];
        if ($v === '') {
            throw new Exception(
                'The LLM Api key (using SS_LLM_CLIENT_API_KEY, SS_LLM_CLIENT_API_KEY_'
                . strtoupper($this->getShortName()) . ' or the SiteConfig) is not configured in this environment.'
            );
        }

        return $v;
    }

    /**
     * Model, in order of preference: argument, SS_LLM_CLIENT_MODEL_{SHORTNAME}, SS_LLM_CLIENT_MODEL,
     * YML client_model_{shortname}, YML client_model, SiteConfig, default model.
     */
    protected function getModel(?string $model = ''): ?string
    {
        $v = $model ?: $this->lookupSetting('LLMModel')[0];

        return $v ?: ($this->getDefaultModel() ?: null);
    }

    public function getClientNameNice(): string
    {
        return $this->getShortName();
    }

    public function getModelNice(): string
    {
        return (string) $this->getModel();
    }

    public function getApiKeyNice(): string
    {
        try {
            $input = $this->getApiKey();
        } catch (Exception) {
            return 'No valid key set';
        }

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
    public function getMaxTokens(): int
    {
        return (int) ($this->config()->get('max_tokens') ?: 0);
    }
}
