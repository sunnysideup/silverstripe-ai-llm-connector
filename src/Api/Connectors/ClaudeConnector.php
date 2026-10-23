<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Api\Connectors;

use Anthropic;
use Exception;
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

class ClaudeConnector extends ConnectorBaseClass
{
    protected string $shortName = 'Claude';

    protected string $defaultModel = 'claude-3-opus-20240229';

    protected function makeClient(): void
    {
        if (!class_exists(Anthropic::class)) {
            throw new Exception(
                'Anthropic SDK is not installed. Please run: composer require mozex/anthropic-php'
            );
        }

        $this->client = Anthropic::client($this->getApiKey());
    }

    /**
     * Send a question to Claude and get a response
     */
    public function askQuestion(string $question, ?string $model = 'claude-3-opus-20240229'): string
    {
        try {

            $response = $this->client->messages()->create([
                'model' => $this->getModel($model),
                'max_tokens' => 1000,
                'messages' => [['role' => 'user', 'content' => $question]],
                'temperature' => 0.7,
            ]);


            return $response->content[0]->text;
        } catch (Exception $exception) {
            error_log('Anthropic API error: ' . $exception->getMessage());
            throw $exception;
        }
    }
}
