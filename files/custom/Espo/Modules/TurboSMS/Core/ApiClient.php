<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Core\HttpClient\ClientFactory;
use Espo\Core\HttpClient\Options;
use Espo\Core\HttpClient\Options\InternalHostRestriction;
use Espo\Core\HttpClient\Options\Redirect;
use Espo\Core\HttpClient\Protocol;
use Espo\Core\Log\DefaultFormatter;
use Espo\Core\Log\Handler\EspoRotatingFileHandler;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use GuzzleHttp\Psr7\Request;
use JsonException;
use Monolog\Level;
use Throwable;

final class ApiClient
{
    private Log $moduleLog;

    public function __construct(
        private ClientFactory $clientFactory,
        private AuthTokenStore $authTokenStore,
        private EntityManager $entityManager,
        private Log $log,
        Config $config,
    ) {
        $handler = new EspoRotatingFileHandler($config, 'data/logs/turbosms.log', 30, Level::Info);
        $handler->setFormatter(new DefaultFormatter());
        $this->moduleLog = new Log('TurboSMS', [$handler]);
    }

    public function integration(): Integration
    {
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'TurboSMS');

        if (!$integration instanceof Integration || !$integration->isEnabled()) {
            throw new ApiException('TurboSMS integration is not enabled.');
        }

        return $integration;
    }

    public function send(array $body): array
    {
        return $this->request('message/send.json', $body, [0, 800, 801, 802, 803]);
    }

    public function balance(): float
    {
        $result = $this->request('user/balance.json', []);
        $value = $result['balance'] ?? null;

        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new ApiException('TurboSMS returned an invalid balance.');
        }

        return (float) $value;
    }

    /** @param list<string> $ids */
    public function details(array $ids): array
    {
        if (!$ids || count($ids) > 100 || array_filter($ids, fn ($id) => !DeliveryState::validId($id))) {
            throw new ApiException('Invalid TurboSMS delivery batch.');
        }

        return $this->request('message/details.json', ['messages' => array_values(array_unique($ids))]);
    }

    public function reportError(Throwable $error): void
    {
        // Do not log request bodies, authorization headers or transport exception messages.
        $message = $error instanceof ApiException ? $error->getMessage() : 'TurboSMS synchronization failed.';
        $this->moduleLog->error($message, ['code' => $error->getCode()]);
        $this->log->error($message, ['code' => $error->getCode()]);
    }

    private function request(string $endpoint, array $body, array $codes = [0]): array
    {
        $integration = $this->integration();
        $token = $this->authTokenStore->token();

        if ($token === '') {
            throw new ApiException('TurboSMS auth token is not configured.');
        }

        $connectTimeout = max(1, min(30, (int) ($integration->get('turboSmsConnectTimeout') ?: 5)));
        $requestTimeout = max($connectTimeout, min(120, (int) ($integration->get('turboSmsRequestTimeout') ?: 30)));
        $options = new Options(
            protocols: [Protocol::https],
            redirect: new Redirect(allow: false),
            timeout: $requestTimeout,
            connectTimeout: $connectTimeout,
            internalHostRestriction: new InternalHostRestriction(restrict: true, allowed: ['api.turbosms.ua:443']),
        );

        try {
            $response = $this->clientFactory->create($options)->send(new Request(
                'POST',
                'https://api.turbosms.ua/' . $endpoint,
                ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
                json_encode($body ?: (object) [], JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable) {
            throw new ApiException('TurboSMS request failed; submission result may be unknown.', true);
        }

        if ($response->getStatusCode() !== 200) {
            throw new ApiException('TurboSMS HTTP error ' . $response->getStatusCode() . '.', true);
        }

        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('TurboSMS returned invalid JSON.', true);
        }

        if (!is_array($data) || !is_int($data['response_code'] ?? null)) {
            throw new ApiException('TurboSMS returned an invalid response.', true);
        }

        $code = $data['response_code'];

        if (!in_array($code, $codes, true)) {
            throw new ApiException('TurboSMS rejected the request (code ' . $code . ').', false, $code);
        }

        if (!is_array($data['response_result'] ?? null)) {
            throw new ApiException('TurboSMS response result is missing.', true);
        }

        if ($integration->get('turboSmsIsLogging')) {
            $this->moduleLog->info('TurboSMS request completed.', ['method' => $endpoint, 'code' => $code]);
        }

        return $data['response_result'];
    }
}
