<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Api\Connectors;

use Exception;
use Gemini;
use Gemini\Client as GeminiClient;
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

class GeminiConnector extends ConnectorBaseClass
{
    protected string $shortName = 'Gemini';

    protected string $defaultModel = 'gemini-2.5-flash';

    protected function makeClient(): void
    {
        if (!class_exists(Gemini::class)) {
            throw new Exception(
                'Google Gemini SDK is not installed. Please run: composer require google-gemini-php/client'
            );
        }

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            throw new Exception('Google Gemini API key is missing. Set SS_LLM_CLIENT_API_KEY_GEMINI in your environment.');
        }

        $this->client = Gemini::client($apiKey);

        if (!$this->client || !($this->client instanceof GeminiClient)) {
            user_error('Could not create Gemini client. Please check your API key.');
        }
    }

    /**
     * Send a prompt/question to Gemini and return the text response.
     */
    public function askQuestion(string $question, ?string $model = ''): string
    {
        try {
            $selectedModel = $this->getModel($model);

            $response = $this->client
                ->generativeModel(model: $selectedModel)
                ->generateContent($question);

            return $response->text();
        } catch (Exception $exception) {
            error_log('Gemini API error: ' . $exception->getMessage());
            throw $exception;
        }
    }
}
