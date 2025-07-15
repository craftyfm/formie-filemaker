<?php

namespace craftyfm\craftformiefilemaker;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GuzzleHttp\Client;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use verbb\formie\base\Integration;
use verbb\formie\base\Webhook;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\Formie;
use verbb\formie\models\IntegrationFormSettings;
use yii\base\Exception;

class WebhookFilemaker extends Webhook
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('formie', 'Filemaker Webhook');
    }

    // Properties
    // =========================================================================

    public ?string $webhook = null;
    public ?string $postUrl = null;
    public ?string $authUrl = null;
    public ?string $username = null;
    public ?string $password = null;
    public ?string $token = null;
    public ?int $length = null;
    public ?string $host = null;


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Send your form content to any URL you provide.');
    }

    public function getHost(): string
    {
        return preg_replace('#^https?://#', '', UrlHelper::hostInfo($this->webhook));
    }


    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['webhook'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }

    public function getIconUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl("@craftyfm/craftformiefilemaker/icon.svg", true);
    }

    /**
     * @throws SyntaxError
     * @throws Exception
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function getSettingsHtml(): string
    {

        // Craft::dd((new \craft\web\View)->getCpTemplateRoots());
        return Craft::$app->getView()->renderTemplate("formie-filemaker/_formie-filemaker-plugin-settings", [
            'integration' => $this,
        ]);
    }

    /**
     * @throws SyntaxError
     * @throws Exception
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function getFormSettingsHtml($form): string
    {
        return Craft::$app->getView()->renderTemplate("formie-filemaker/_formie-filemaker-form-settings", [
            'integration' => $this,
            'form' => $form,
        ]);
    }

    /**
     * @throws IntegrationException
     */
    public function fetchFormSettings(): IntegrationFormSettings
    {
        $settings = [];
        $payload = [];

        try {
            $formId = Craft::$app->getRequest()->getParam('formId');
            $form = Formie::$plugin->getForms()->getFormById($formId);

            // Generate and send a test payload to the webhook endpoint
            $submission = new Submission();
            $submission->setForm($form);

            Formie::$plugin->getSubmissions()->populateFakeSubmission($submission);

            // Ensure we're fetching the webhook from the form settings, or global integration settings
            $webhook = $form->settings->integrations[$this->handle]['webhook'] ?? $this->webhook;

            $payload = $this->generatePayloadValues($submission);
            $response = $this->getClient()->request('POST', $this->getWebhookUrl($webhook, $submission), $payload);

            $rawResponse = (string)$response->getBody();
            $json = Json::decode($rawResponse);

            $settings = [
                'response' => $response,
                'json' => $json,
            ];
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}. Payload: “{payload}”. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => Json::encode($payload),
                'response' => $rawResponse ?? '',
            ]));

            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    /**
     * @throws IntegrationException
     */
    public function sendPayload(Submission $submission): bool
    {
        $payload = [];

        try {
            // Either construct the payload yourself manually or get Formie to do it
            $payload = $this->generatePayloadValues($submission);

            $body = [
                "fieldData" => [
                    "webhook_payload" => json_encode($payload)
                ]
            ];

            $this->length = strlen(json_encode($body));

            $client = new Client();

            $headers = ['headers' => [
                'Host' => $this->getHost(),
                'Content-Type' => 'application/json',
                'Content-Length' => $this->length,
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAuthToken()
            ],
                'body' => json_encode($body)
            ];

            $client->post($this->webhook, $headers);
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}. Payload: “{payload}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => Json::encode($payload),
            ]));

            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    /**
     * @throws IntegrationException
     */
    public function getClient(): Client
    {
        // We memoize the client for performance, in case we make multiple requests.
        if ($this->_client) {
            return $this->_client;
        }

        //get or set a refreshed token
        $headers = [
            'headers' => [
                // 'Connection' => 'keep-alive',
                'Host' => $this->getHost(),
                'Content-Type' => 'application/json',
                'Content-Length' => $this->length,
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAuthToken()
            ]
        ];

        // Create a Guzzle client to send the payload.
        return $this->_client = Craft::createGuzzleClient($headers);
    }

    /**
     * @throws IntegrationException
     */
    public function getAuthToken(): ?string
    {
        try {
            // Validate required configuration
            if (empty($this->authUrl) || empty($this->username) || empty($this->password)) {
                Integration::error($this, Craft::t('formie', 'Missing authentication configuration: authUrl, username, or password'));
                return null;
            }

            $client = new Client([
                'base_uri' => $this->authUrl,
                'verify' => false
            ]);

            // Create Basic Auth string
            $basicAuthString = 'Basic ' . base64_encode($this->username . ':' . $this->password);

            // Request token
            $response = $client->request('POST', '', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $basicAuthString
                ],
                'body' => '',
                'debug' => false, // Set to true only for debugging
            ]);

            $json = $response->getBody()->getContents();

            // Check if response is empty
            if (empty($json)) {
                Integration::error($this, Craft::t('formie', 'Empty response from auth endpoint'));
                return null;
            }

            $data = json_decode($json, true);

            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                Integration::error($this, Craft::t('formie', 'Invalid JSON response from auth endpoint: {error}', [
                    'error' => json_last_error_msg()
                ]));
                return null;
            }

            // Validate response structure and data presence
            if (empty($data['response']['token'])) {
                Integration::error($this, Craft::t('formie', 'Token not found in auth response or token is empty'));
                return null;
            }

            return $data['response']['token'];

        } catch (Throwable $e) {
            // Auth errors to log
            Integration::error($this, Craft::t('formie', 'API error: "{message}" {file}:{line}. AuthURL: "{authurl}"', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'authurl' => $this->authUrl ?? 'not set',
            ]));

            Integration::apiError($this, $e);

            return null;
        }
    }

    /**
     * @throws IntegrationException
     */
    public function fetchConnection(): bool
    {
        try {
            // Create a simple API call to `/account` to test the connection (in the integration settings)
            // any errors will be safely caught, logged and shown in the UI.
            //$response = $this->request('GET', $this->webhook);

            $client = new Client();

            $headers = ['headers' => [
                'Host' => $this->getHost(),
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAuthToken()
            ]
            ];

            $response = $client->get($this->webhook, $headers);

            $json = $response->getBody()->getContents();
            $data = json_decode($json);

            $status = $data->messages[0]->message;

            $webhook_payload = $data->response->data[0]->fieldData->webhook_payload;

            if ($status == "OK" && $webhook_payload != null) {
                return true;
            }


        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }
}