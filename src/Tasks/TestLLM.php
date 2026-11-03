<?php


namespace Sunnysideup\AiLlmConnector\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Security\SecurityToken;
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

class TestLLM extends BuildTask
{
    protected $title = 'Test LLM';

    protected $description = 'Connect to LLM and see if something comes back.';

    protected $enabled = true;

    private static $segment = 'acm-test-llm';

    protected $processor;

    public function run($request)
    {
        $isCli = Director::is_cli();
        $obj = ConnectorBaseClass::inst();
        $question = 'Give me a random amazing fact about nature.';
        $requestedQuestion = $request->requestVar('question');
        if ($requestedQuestion && is_string($requestedQuestion)) {
            // keep a bound on what is sent to the LLM
            $question = mb_substr($requestedQuestion, 0, 2000);
        }
        $escape = fn (string $value): string => $isCli ? $value : Convert::raw2xml($value);

        // calling the LLM costs money: only on POST with a valid security token (or from the command line)
        $mayAsk = $isCli || ($request->isPOST() && SecurityToken::inst()->checkRequest($request));
        if (! $mayAsk) {
            echo '<form method="post" action="">'
                . '<input type="hidden" name="' . Convert::raw2att(SecurityToken::inst()->getName()) . '" value="' . Convert::raw2att(SecurityToken::inst()->getValue()) . '" />'
                . '<label>Question: <input type="text" name="question" size="80" value="' . Convert::raw2att($question) . '" /></label> '
                . '<input type="submit" value="Ask" />'
                . '</form>';

            return;
        }

        echo '<hr>';
        echo 'Question: <br /><em>' . $escape($question) . '</em>';
        echo '<hr>';
        echo '<hr>';
        echo '<hr>';
        echo 'Answer: <br /><em>' . $escape((string) $obj->askQuestion($question)) . '</em>';
    }
}
