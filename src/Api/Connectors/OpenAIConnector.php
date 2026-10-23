<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Api\Connectors;

use Exception;
use GuzzleHttp\Client;
use OpenAI;
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;
use OpenAI\Client as OpenAIClient;
use Psr\Http\Client\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface as ExceptionTimeoutExceptionInterface;

class OpenAIConnector extends ConnectorBaseClass
{

    protected string $shortName = 'OpenAI';

    protected string $defaultModel = 'gpt-4o'; //gpt-4.1-mini


    protected function makeClient(): void
    {
        if (!class_exists(OpenAI::class)) {
            throw new Exception(
                'OpenAI SDK is not installed. Please run: composer require openai-php/client'
            );
        }

        $this->client = OpenAI::factory()
            ->withApiKey($this->getApiKey())
            ->withHttpClient($this->makeHttpClient($this->getTimeout()))
            ->make();
        if (! $this->client || !($this->client instanceof OpenAIClient)) {
            user_error('Could not create OpenAI client. Please check your API key.');
        }
    }


    protected function makeHttpClient(int $timeout): Client
    {
        return new Client([
            'timeout' => $timeout,
            'connect_timeout' => 10,
            'read_timeout' => 120,
            'http_errors' => true,
        ]);
        // $symfony = new CurlHttpClient([
        //     'timeout'         => $timeout,   // overall
        //     'max_duration'    => max($timeout, 300),
        //     'idle_timeout'    => 90,         // no bytes for this long -> fail
        //     'read_timeout'    => 120,        // between chunks
        //     'connect_timeout' => 10,
        //     // 'proxy'        => null,       // ensure no proxy if your infra adds one
        //     // 'http_version' => '2',       // OpenAI supports h2; optional
        // ]);

        // return new Psr18Client($symfony);
    }

    public function askQuestion(string $question, ?string $model = ''): string
    {
        $payload = [
            'model' => $this->getModel($model),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $question
                ]
            ],
            'temperature' => $this->getTemperature(),
        ];

        // keep responses smaller to reduce server think-time:
        $maxTokens = $this->getMaxTokens();
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        return $this->chatWithRetry($payload, 3, 500);
    }

    private function chatWithRetry(array $payload, int $retries, int $initialDelayMs): string
    {
        $delayMs = $initialDelayMs;
        for ($i = 0; $i <= $retries; $i++) {
            try {
                $response = $this->client->chat()->create($payload);
                return $response->choices[0]->message->content;
            } catch (ExceptionTimeoutExceptionInterface | ClientExceptionInterface $e) {
                if ($i === $retries) {
                    throw $e; // bubble up after last attempt
                }

                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }

        return '';
    }
}
