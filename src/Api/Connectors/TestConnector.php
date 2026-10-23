<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Api\Connectors;

use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

class TestConnector extends ConnectorBaseClass
{

    protected string $shortName = 'Test';

    protected string $defaultModel = 'test-model';

    protected function makeClient(): void
    {
        $this->client = null;
    }

    /**
     * Send a question
     */
    public function askQuestion(string $question, ?string $model = ''): string
    {
        return 'This is a test response from the TestConnector.';
    }
}
