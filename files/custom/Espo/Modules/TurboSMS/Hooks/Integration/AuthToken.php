<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Hooks\Integration;

use Espo\Core\Exceptions\Conflict;
use Espo\Entities\Integration;
use Espo\Modules\TurboSMS\Core\AuthTokenStore;
use Espo\ORM\Entity;
use InvalidArgumentException;

final class AuthToken
{
    public static int $order = 10;

    private const INTEGRATION_ID = 'TurboSMS';
    private const INPUT_FIELD = 'turboSmsAuthToken';

    public function __construct(
        private AuthTokenStore $tokenStore,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity instanceof Integration || $entity->getId() !== self::INTEGRATION_ID) {
            return;
        }

        $submitted = $entity->has(self::INPUT_FIELD);
        $value = null;

        if ($submitted) {
            $rawValue = $entity->get(self::INPUT_FIELD);

            if ($rawValue !== null && !is_string($rawValue)) {
                throw new Conflict('TurboSMS auth token must be a string.');
            }

            $rawValue = trim((string) $rawValue);
            $value = $rawValue !== '' ? $rawValue : null;
        }

        $entity->clear(self::INPUT_FIELD);

        try {
            if ($value !== null) {
                $this->tokenStore->persist($value);
            } else {
                $this->tokenStore->syncDescription();
            }
        } catch (InvalidArgumentException $exception) {
            throw new Conflict($exception->getMessage());
        }

        if (
            $entity->get('enabled')
            && ($submitted || $entity->isAttributeChanged('enabled'))
            && !$this->tokenStore->isConfigured()
        ) {
            throw new Conflict('TurboSMS auth token is required for an enabled integration.');
        }
    }
}
