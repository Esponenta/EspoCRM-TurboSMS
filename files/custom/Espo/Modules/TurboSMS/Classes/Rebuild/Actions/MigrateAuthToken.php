<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Classes\Rebuild\Actions;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Entities\Integration;
use Espo\Modules\TurboSMS\Core\AuthTokenStore;
use Espo\ORM\EntityManager;
use RuntimeException;

final class MigrateAuthToken implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private AuthTokenStore $tokenStore,
    ) {
    }

    public function process(): void
    {
        $integration = $this->entityManager
            ->getRDBRepositoryByClass(Integration::class)
            ->getById('TurboSMS');

        if (!$integration) {
            return;
        }

        if (!$integration->has('turboSmsAuthToken')) {
            return;
        }

        $legacyToken = trim((string) $integration->get('turboSmsAuthToken'));

        if ($legacyToken !== '') {
            if (!$this->tokenStore->isConfigured()) {
                $this->tokenStore->persist($legacyToken);
            }

            if (!$this->tokenStore->isConfigured()) {
                throw new RuntimeException('Could not migrate the TurboSMS auth token.');
            }
        }

        $integration->clear('turboSmsAuthToken');
        $this->entityManager->saveEntity($integration, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
        ]);
    }
}
