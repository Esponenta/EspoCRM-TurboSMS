<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Sms;
use Espo\ORM\EntityManager;

final class DeliveryTracker
{
    public const SAVE_OPTION = 'turboSmsTracking';
    public const FIELDS = ['turboSmsSendType', 'turboSmsDeliveryStatus', 'turboSmsDeliveryData',
        'turboSmsNextCheckAt', 'turboSmsTrackingEndsAt'];

    public function __construct(private EntityManager $entityManager)
    {}

    public function start(Sms $sms, array $numbers, string $type, int $trackingDays): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($sms, $numbers, $type, $trackingDays): void {
            $stored = $sms->isNew() ? null : $this->entityManager->getRDBRepository(Sms::ENTITY_TYPE)
                ->where(['id' => $sms->getId()])->forUpdate()->findOne();

            if ($sms->get('turboSmsDeliveryData') || $stored?->get('turboSmsDeliveryData')) {
                throw new Conflict('This SMS has already been submitted to TurboSMS. Check its delivery details.');
            }

            $sms->set([
                'status' => Sms::STATUS_SENDING,
                'turboSmsSendType' => $type,
                'turboSmsTrackingEndsAt' => gmdate('Y-m-d H:i:s', time() + max(1, min(30, $trackingDays)) * 86400),
            ]);
            $this->save($sms, DeliveryState::initial($numbers, $type));
        });
    }

    public function accept(Sms $sms, array $response): bool
    {
        $rows = DeliveryState::capture(DeliveryState::recipients($sms->get('turboSmsDeliveryData')), $response, gmdate('Y-m-d H:i:s'));
        $accepted = (bool) array_filter($rows, static fn ($row) => $row['messageId'] !== null);

        if ($accepted) {
            $sms->setAsSent();
        } elseif (DeliveryState::aggregate($rows) === 'Failed') {
            $sms->setStatus(Sms::STATUS_FAILED);
        } else {
            $sms->setStatus(Sms::STATUS_SENDING);
        }

        $this->save($sms, $rows);

        return $accepted;
    }

    public function reject(Sms $sms, ApiException $error): void
    {
        $sms->setStatus($error->uncertain ? Sms::STATUS_SENDING : Sms::STATUS_FAILED);
        $this->save($sms, DeliveryState::rejected(DeliveryState::recipients($sms->get('turboSmsDeliveryData')), $error));
    }

    public function save(Sms $sms, array $rows, bool $expired = false): void
    {
        $sms->set([
            'turboSmsDeliveryData' => (object) ['recipients' => $rows, 'trackingExpired' => $expired],
            'turboSmsDeliveryStatus' => DeliveryState::aggregate($rows),
            'turboSmsNextCheckAt' => $expired ? null : DeliveryState::nextCheck($rows),
        ]);
        $this->entityManager->saveEntity($sms, [SaveOption::SILENT => true, self::SAVE_OPTION => true]);
    }
}
