<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Crypt;
use Espo\Entities\AppSecret;
use Espo\ORM\EntityManager;
use Espo\Tools\AppSecret\SecretProvider;
use InvalidArgumentException;

final class AuthTokenStore
{
    public const SECRET_NAME = 'turbo_sms_auth_token';

    private const DESCRIPTION = '[TurboSMS](#Admin/integrations/name=TurboSMS) · auth token';

    public function __construct(
        private EntityManager $entityManager,
        private SecretProvider $secretProvider,
        private Crypt $crypt,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    public function token(): string
    {
        return trim((string) $this->secretProvider->get(self::SECRET_NAME));
    }

    public function persist(string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('TurboSMS auth token must not be empty.');
        }

        $secret = $this->entityManager
            ->getRDBRepository(AppSecret::ENTITY_TYPE)
            ->where(['name' => self::SECRET_NAME])
            ->findOne();

        if (!$secret) {
            $secret = $this->entityManager->getNewEntity(AppSecret::ENTITY_TYPE);
            $secret->set('name', self::SECRET_NAME);
        }

        if (!$secret instanceof AppSecret) {
            throw new InvalidArgumentException('Could not access the TurboSMS auth token.');
        }

        $secret->set('description', self::DESCRIPTION);
        $secret->setSecretValue($this->crypt->encrypt($value));
        $this->entityManager->saveEntity($secret, [SaveOption::SILENT => true]);
    }

    public function syncDescription(): void
    {
        $secret = $this->entityManager
            ->getRDBRepository(AppSecret::ENTITY_TYPE)
            ->where(['name' => self::SECRET_NAME])
            ->findOne();

        if (!$secret) {
            return;
        }

        if (!$secret instanceof AppSecret) {
            throw new InvalidArgumentException('Could not access the TurboSMS auth token.');
        }

        if ($secret->get('description') === self::DESCRIPTION) {
            return;
        }

        $secret->set('description', self::DESCRIPTION);
        $this->entityManager->saveEntity($secret, [SaveOption::SILENT => true]);
    }
}
