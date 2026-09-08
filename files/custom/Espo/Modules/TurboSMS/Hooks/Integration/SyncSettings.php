<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Hooks\Integration;

use Espo\Core\Exceptions\Conflict;
use Espo\Entities\Integration;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TurboSMS\Core\IntegrationState;
use Espo\Modules\TurboSMS\Core\SyncSchedule;
use Espo\Modules\TurboSMS\Core\SyncSettings as Settings;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use InvalidArgumentException;

final class SyncSettings
{
    public static int $order = 5;

    public function __construct(private EntityManager $entityManager, private SyncSchedule $schedule)
    {}

    public function beforeSave(Entity $entity): void
    {
        if (!$entity instanceof Integration || $entity->getId() !== 'TurboSMS') {
            return;
        }

        try {
            $values = Settings::normalize((array) $entity->getData());
        } catch (InvalidArgumentException $error) {
            throw new Conflict($error->getMessage());
        }

        $userNames = [];

        foreach ($values['turboSmsNotificationUsersIds'] as $id) {
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $id);

            if (!$user instanceof User || !$user->isActive()
                || !in_array($user->getType(), IntegrationState::RECIPIENT_USER_TYPES, true)) {
                throw new Conflict('TurboSMS notifications require active internal user recipients.');
            }

            $userNames[$id] = (string) ($user->get('name') ?: $id);
        }

        $teamNames = [];

        foreach ($values['turboSmsNotificationTeamsIds'] as $id) {
            $team = $this->entityManager->getEntityById(Team::ENTITY_TYPE, $id);

            if (!$team instanceof Team) {
                throw new Conflict('TurboSMS notification teams are invalid.');
            }

            $teamNames[$id] = (string) ($team->get('name') ?: $id);
        }

        $values['turboSmsNotificationUsersNames'] = (object) $userNames;
        $values['turboSmsNotificationTeamsNames'] = (object) $teamNames;
        $old = (array) $entity->getFetched('data');

        foreach (IntegrationState::FIELDS as $field) {
            unset($values[$field]);
        }

        $entity->set('data', (object) $values);

        foreach (IntegrationState::FIELDS as $field) {
            $entity->set($field, $entity->getFetched($field));
        }

        foreach ([
            'enabled',
            'turboSmsLowBalanceEnabled',
            'turboSmsLowBalanceThreshold',
            'turboSmsNotificationUsersIds',
            'turboSmsNotificationTeamsIds',
        ] as $field) {
            $previous = $field === 'enabled' ? $entity->getFetched($field) : ($old[$field] ?? null);

            if ($previous !== $entity->get($field)) {
                $entity->set('turboSmsBalanceAlerted', false);
                break;
            }
        }
    }

    public function afterSave(Entity $entity): void
    {
        if ($entity instanceof Integration && $entity->getId() === 'TurboSMS') {
            $this->schedule->reconcile();
        }
    }
}
