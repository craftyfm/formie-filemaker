<?php

namespace craftyfm\craftformiefilemaker;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craftyfm\craftformiefilemaker\models\Settings;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use verbb\formie\events\RegisterIntegrationsEvent;
use verbb\formie\services\Integrations;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;


/**
 * formie-filemaker plugin
 *
 * @method static FormieFilemaker getInstance()
 * @method Settings getSettings()
 * @author Craftyfm <stuart@x2network.net>
 * @copyright Craftyfm
 * @license https://craftcms.github.io/license/ Craft License
 */
class FormieFilemaker extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
        });

    }

    /**
     * @throws InvalidConfigException
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws Exception
     * @throws LoaderError
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('formie-filemaker/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register our custom integration
        Event::on(Integrations::class, Integrations::EVENT_REGISTER_INTEGRATIONS, function (RegisterIntegrationsEvent $event) {
            $event->webhooks[] = WebhookFilemaker::class;
        });
    }


}
