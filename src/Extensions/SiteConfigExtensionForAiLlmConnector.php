<?php

declare(strict_types=1);

namespace Sunnysideup\AiLlmConnector\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLReadonlyField;
use SilverStripe\Forms\TextField;
use Sunnysideup\AiLlmConnector\Api\ConnectorBaseClass;

/**
 * Lets CMS admins choose the LLM, model and API key on the SiteConfig
 * (Settings > LLM). The values are read by `ConnectorBaseClass`.
 */
class SiteConfigExtensionForAiLlmConnector extends Extension
{
    private static $db = [
        'LLMClient' => 'Varchar(50)',
        'LLMModel' => 'Varchar(50)',
        'LLMKey' => 'Text',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $this->addLLMFields($fields);
        // fields that are overruled by the environment or YML config can not be edited
        foreach (['LLMClient', 'LLMModel', 'LLMKey'] as $name) {
            $overruledBy = ConnectorBaseClass::get_overruled_by($name);
            if ($overruledBy === '') {
                continue;
            }

            $field = $fields->dataFieldByName($name);
            if (!$field) {
                continue;
            }

            $readonly = $field->performReadonlyTransformation();
            $readonly->setDescription(
                'This value can not be edited here, because it is overruled by ' . $overruledBy . '.'
            );
            $fields->replaceField($name, $readonly);
        }
    }

    protected function addLLMFields(FieldList $fields)
    {
        $fields->addFieldsToTab(
            'Root.LLM',
            [
                DropdownField::create(
                    'LLMClient',
                    'Type of LLM you are using.',
                    ConnectorBaseClass::inst()->getClientNameList()
                )
                    ->setEmptyString('-- Select LLM Type --'),
                TextField::create('LLMModel', 'Engine you are using')
                    ->setDescription('
                        e.g. gpt-3.5-turbo, gpt-4, claude-2, gemini-2.5-flash, just leave blank for default.
                        <br />
                        <br />Right now, the selected model is: ' . (ConnectorBaseClass::inst()->getModelNice() ?: '--not set ---')),
                TextField::create('LLMKey', 'LLM Key ')
                    ->setDescription('e.g. sk-1234
                    <br />
                    <br />You can get your key from your LLM provider.
                    <br />For OpenAI, you can get it from <a href="https://platform.openai.com/account/api-keys" target="_blank">here</a>.
                    <br />For Anthropic, you can get it from <a href="https://console.anthropic.com/keys" target="_blank">here</a>.
                    <br />For Google Gemini, you can get it from <a href="https://aistudio.google.com/apikey" target="_blank">here</a>.
                    <br />Right now, the entered key is: ' . (ConnectorBaseClass::inst()->getApiKeyNice() ?: '--not set ---')),
                HTMLReadonlyField::create(
                    'TestYourLLM',
                    'Test your LLM',
                    '<a href="' . ConnectorBaseClass::inst()->getTestLink() . '" target="_blank">Test your LLM</a>',
                ),
            ]
        );
    }
}
