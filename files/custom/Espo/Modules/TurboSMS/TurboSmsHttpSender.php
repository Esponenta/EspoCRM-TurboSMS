<?php

namespace Espo\Modules\TurboSMS;

use Espo\Core\Exceptions\Error;
use Espo\Core\HttpClient\ClientFactory;
use Espo\Core\HttpClient\ConnectErrorReason;
use Espo\Core\HttpClient\Exceptions\ConnectException;
use Espo\Core\HttpClient\Options;
use Espo\Core\HttpClient\Options\InternalHostRestriction;
use Espo\Core\HttpClient\Options\Redirect;
use Espo\Core\HttpClient\Protocol;
use Espo\Core\Log\DefaultFormatter;
use Espo\Core\Log\Handler\EspoRotatingFileHandler;
use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\Modules\TurboSMS\Core\AuthTokenStore;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\Request;

use JsonException;
use Monolog\Level;
use Throwable;

class TurboSmsHttpSender implements Sender
{
    private const string API_URL = 'https://api.turbosms.ua/';
    private const string ENDPOINT_SEND = 'message/send.json';
    private const string ENDPOINT_BALANCE = 'user/balance.json';
    private const string LOG_PATH = 'data/logs/turbosms.log';
    private const int LOG_MAX_FILE_NUMBER = 30;
    private const int TIMEOUT = 15;

    private const array SUCCESS_CODES = [0, 800, 801, 802, 803];
    private const array RESPONSE_MESSAGES = [
        0 => 'Request has been processed successfully.',
        1 => 'Ping method call succeeded.',
        103 => 'Missing authentication token.',
        104 => 'Request data is missing.',
        105 => 'Authentication failed, invalid token.',
        106 => 'User is blocked. API access is unavailable until unblocked.',
        200 => 'Sender is missing or empty.',
        201 => 'Text is missing or empty.',
        202 => 'Recipients list is missing or empty.',
        203 => 'Insufficient balance.',
        204 => 'Required button parameters are missing or empty.',
        205 => 'Button text is missing or empty.',
        206 => 'Button target URL is missing or empty.',
        300 => 'Invalid request structure or data.',
        301 => 'Invalid authentication token.',
        302 => 'Invalid sender.',
        303 => 'Invalid scheduled send date.',
        304 => 'Invalid text value or unsupported encoding (UTF-8 required).',
        305 => 'Invalid recipient phone number.',
        306 => 'Invalid ttl value; must be an integer.',
        307 => 'Invalid message_id format.',
        308 => 'Invalid id format for file/details.',
        400 => 'Sender is not allowed for the current user.',
        401 => 'Sender is allowed but not active for current period.',
        402 => 'Invalid image file type.',
        403 => 'Invalid scheduled send date (outside allowed limits).',
        404 => 'Recipient is in stop-list or ignore-list.',
        405 => 'Invalid recipients count.',
        406 => 'Recipient country is not allowed for current user.',
        407 => 'Recipient duplicate in mailing list; duplicates are ignored.',
        408 => 'Button text is too long (max 30 chars).',
        409 => 'Invalid ttl value (outside allowed limits).',
        410 => 'Invalid content in transactional message.',
        411 => 'One or more parameters have invalid values.',
        412 => 'Message contains prohibited fragments.',
        413 => 'Message text is too long.',
        414 => 'Message with provided message_id is not available for current user.',
        415 => 'Transactional messages are forbidden from a shared sender.',
        416 => 'No template found for provided transactional message.',
        417 => 'File with provided id does not exist or is unavailable.',
        418 => 'Uploaded file is not found or empty.',
        419 => 'Unsupported file type.',
        420 => 'File size exceeds maximum allowed limit (3 MB).',
        500 => 'Failed to convert result data to JSON.',
        501 => 'Failed to convert result data to XML.',
        502 => 'Failed to parse request body (invalid format).',
        503 => 'Failed to send SMS message.',
        504 => 'Failed to send Viber message.',
        505 => 'Failed to store file or image.',
        800 => 'Messages queued successfully; some may require pre-moderation.',
        801 => 'Messages sent successfully.',
        802 => 'Messages queued; some recipients were excluded (see response details).',
        803 => 'Messages sent; some recipients were excluded (see response details).',
        999 => 'General API execution error.',
    ];

    private EntityManager $entityManager;
    private Log $log;
    private Log $moduleLog;

    private ?Integration $integration;
    private string $authToken;
    private string $sendType;
    private string $senderSms;
    private string $senderViber;
    private bool $isLogging = false;
    private int $sendTimeout = self::TIMEOUT;

    /**
     * @throws Error
     */
    public function __construct(
        Config $config,
        EntityManager $entityManager,
        Log $log,
        AuthTokenStore $authTokenStore,
        private ClientFactory $clientFactory,
    )
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
        $this->moduleLog = $this->createModuleLog($config);
        $this->integration = $this->getIntegrationEntity();
        $this->authToken = $authTokenStore->token();

        if ($this->authToken === '') {
            $this->log('TurboSMS. No auth token.', true);

            throw new Error('TurboSMS. No auth token.');
        }

        $this->sendType = strtolower(trim((string)($this->integration->get('turboSmsSendType') ?: 'sms')));
        $this->senderSms = trim((string)$this->integration->get('turboSmsSenderSms'));
        $this->senderViber = trim((string)$this->integration->get('turboSmsSenderViber'));
        $this->isLogging = (bool)$this->integration->get('turboSmsIsLogging');

        $sendTimeout = (int)($this->integration->get('turboSmsSendTimeout') ?: self::TIMEOUT);
        $this->sendTimeout = $sendTimeout > 0 ? $sendTimeout : self::TIMEOUT;
    }

    /**
     * @throws Error
     * @throws JsonException
     */
    public function send(Sms $sms): void
    {
        $rawRecipientNumbers = $sms->getToNumberList();

        if (!count($rawRecipientNumbers)) {
            $this->log('TurboSMS. No recipient phone number.', true);

            throw new Error('TurboSMS. No recipient phone number.');
        }

        $normalizedRecipients = [];

        foreach ($rawRecipientNumbers as $number) {
            $normalized = self::normalizePhone($number);

            if ($normalized === '') {
                continue;
            }

            $normalizedRecipients[] = $normalized;
        }

        $normalizedRecipients = array_values(array_unique($normalizedRecipients));

        if (!count($normalizedRecipients)) {
            $this->log('TurboSMS. Recipient phone numbers are invalid.', true);

            throw new Error('TurboSMS. Recipient phone numbers are invalid.');
        }

        $body = $this->buildSendBody($normalizedRecipients, $sms->getBody());

        $balance = $this->fetchBalance();

        if ($balance <= 0.0) {
            $this->log('TurboSMS. Balance is 0. Sending is not possible.', true);

            throw new Error('TurboSMS. Balance is 0. Sending is not possible.');
        } else {
            $this->log("TurboSMS. Balance is {$balance}.");
        }

        $response = $this->request(self::ENDPOINT_SEND, $body);

        $this->assertSuccessfulResponse($response);
    }

    /**
     * @throws Error
     */
    private function buildSendBody(array $recipients, string $message): array
    {
        $body = [
            'recipients' => $recipients,
        ];

        $message = trim($message);

        if ($message === '') {
            $this->log('TurboSMS. SMS body is empty.', true);

            throw new Error('TurboSMS. SMS body is empty.');
        }

        switch ($this->sendType) {
            case 'sms':
                if ($this->senderSms === '') {
                    $this->log('TurboSMS. SMS sender is not configured.', true);

                    throw new Error('TurboSMS. SMS sender is not configured.');
                }

                $body['sms'] = [
                    'sender' => $this->senderSms,
                    'text' => $message,
                ];

                break;

            case 'viber':
                if ($this->senderViber === '') {
                    $this->log('TurboSMS. Viber sender is not configured.', true);

                    throw new Error('TurboSMS. Viber sender is not configured.');
                }

                $body['viber'] = [
                    'sender' => $this->senderViber,
                    'text' => $message,
                ];

                break;

            case 'hybrid':
                if ($this->senderViber === '') {
                    $this->log('TurboSMS. Viber sender is not configured.', true);

                    throw new Error('TurboSMS. Viber sender is not configured.');
                }

                if ($this->senderSms === '') {
                    $this->log('TurboSMS. SMS sender is not configured.', true);

                    throw new Error('TurboSMS. SMS sender is not configured.');
                }

                $body['viber'] = [
                    'sender' => $this->senderViber,
                    'text' => $message,
                ];

                $body['sms'] = [
                    'sender' => $this->senderSms,
                    'text' => $message,
                ];

                break;

            default:
                $this->log('TurboSMS. Unsupported send type. Allowed: sms, viber, hybrid.', true);

                throw new Error('TurboSMS. Unsupported send type. Allowed: sms, viber, hybrid.');
        }

        return $body;
    }

    /**
     * @throws Error
     * @throws JsonException
     */
    private function fetchBalance(): float
    {
        $response = $this->request(self::ENDPOINT_BALANCE, []);

        $code = $this->extractResponseCode($response, 'balance response');

        if ($code !== 0) {
            $message = self::resolveResponseMessage($code);

            $this->log('TurboSMS. Balance request error. Message: ' . $message, true);

            throw new Error('TurboSMS. Balance request error. Code: ' . $code . '. ' . $message);
        }

        $balance = $response['response_result']['balance'] ?? null;

        if ($balance === null) {
            $this->log('TurboSMS. Balance response is invalid.', true);

            throw new Error('TurboSMS. Balance response is invalid.');
        }

        return (float)$balance;
    }

    /**
     * @throws Error
     * @throws JsonException
     */
    private function request(string $endpoint, array $body): array
    {
        $url = $this->buildUrl($endpoint);
        $options = new Options(
            protocols: [Protocol::https],
            redirect: new Redirect(allow: false),
            timeout: $this->sendTimeout,
            connectTimeout: $this->sendTimeout,
            internalHostRestriction: new InternalHostRestriction(
                restrict: true,
                allowed: ['api.turbosms.ua:443'],
            ),
        );

        try {
            $response = $this->clientFactory
                ->create($options)
                ->send(new Request(
                    'POST',
                    $url,
                    [
                        'Authorization' => 'Bearer ' . $this->authToken,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    json_encode($body, JSON_THROW_ON_ERROR),
                ));
        } catch (ConnectException $exception) {
            if ($exception->getReason() === ConnectErrorReason::Timeout) {
                $this->log('TurboSMS. Request timeout.', true);

                throw new Error('TurboSMS. Request timeout.');
            }

            $this->log('TurboSMS. Connection error.', true);

            throw new Error('TurboSMS. Connection error.');
        } catch (JsonException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->log('TurboSMS. Connection error: ' . $exception->getMessage(), true);

            throw new Error('TurboSMS. Connection error.');
        }

        $rawResponse = (string) $response->getBody();
        $httpCode = $response->getStatusCode();

        if ($rawResponse === '') {
            $this->log('TurboSMS. Returned an empty response.', true);

            throw new Error('TurboSMS. Returned an empty response.');
        }

        $decoded = Json::decode($rawResponse, true);

        if (!is_array($decoded)) {
            $this->log('TurboSMS. Returned invalid JSON.', true);

            throw new Error('TurboSMS. Returned invalid JSON.');
        }

        if ($httpCode !== 200) {
            $this->log("TurboSMS. HTTP error: {$httpCode}", true);

            throw new Error("TurboSMS. HTTP error: {$httpCode}");
        }

        return $decoded;
    }

    /**
     * @throws Error
     */
    private function assertSuccessfulResponse(array $response): void
    {
        $code = $this->extractResponseCode($response, 'send response');

        if (!in_array($code, self::SUCCESS_CODES, true)) {
            $message = self::resolveResponseMessage($code);

            $this->log("TurboSMS. Sending error. Message: {$message}", true);

            throw new Error("TurboSMS. Sending error. Code: {$code}");
        }

        $resultList = $response['response_result'] ?? null;

        if (!is_array($resultList)) {
            return;
        }

        foreach ($resultList as $row) {
            if (!is_array($row)) {
                continue;
            }

            $recipientCode = $this->extractResponseCode($row, 'recipient response');
            $message = self::resolveResponseMessage($recipientCode);

            if (!in_array($recipientCode, self::SUCCESS_CODES, true)) {
                $this->log("TurboSMS. Recipient error. Message: {$message}", true);

                throw new Error("TurboSMS. Recipient error. Code: {$recipientCode}");
            } else {
                $messageId = (string)($row['message_id'] ?? 'n/a');

                $this->log("TurboSMS. MessageID: {$messageId} - {$message}");
            }
        }
    }

    /**
     * @throws Error
     */
    private function extractResponseCode(array $payload, string $context): int
    {
        if (!array_key_exists('response_code', $payload) || !is_numeric($payload['response_code'])) {
            $this->log("TurboSMS. {$context} is invalid: response_code is missing or not numeric.", true);

            throw new Error("TurboSMS. {$context} is invalid: response_code is missing or not numeric.");
        }

        return (int)$payload['response_code'];
    }

    /**
     * @throws Error
     */
    private function getIntegrationEntity(): Integration
    {
        $entity = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'TurboSMS');

        if (!$entity instanceof Integration || !$entity->get('enabled')) {
            throw new Error('TurboSMS. Integration is not enabled.');
        }

        return $entity;
    }

    private function buildUrl(string $endpoint): string
    {
        return self::API_URL . $endpoint;
    }

    private function log(string $message, bool $isError = false): void
    {
        if ($this->isLogging && !$isError) {
            $this->moduleLog->info($message);
        }

        if ($isError) {
            $this->moduleLog->error($message);
            $this->log->error($message);
        }
    }

    private function createModuleLog(Config $config): Log
    {
        $handler = new EspoRotatingFileHandler(
            $config,
            self::LOG_PATH,
            self::LOG_MAX_FILE_NUMBER,
            Level::Info,
        );

        $handler->setFormatter(new DefaultFormatter());

        return new Log('TurboSMS', [$handler]);
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (!$digits) {
            return '';
        }

        if (strlen($digits) === 10) {
            return '+38' . $digits;
        }

        return '+' . $digits;
    }

    private static function resolveResponseMessage(int $code): string
    {
        return self::RESPONSE_MESSAGES[$code] ?? 'Unknown error.';
    }
}
