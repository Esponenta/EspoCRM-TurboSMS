<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Hooks\Sms;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\Sms;
use Espo\Modules\TurboSMS\Core\DeliveryTracker;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

final class DeliveryFields implements BeforeSave
{
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get(DeliveryTracker::SAVE_OPTION) === true) {
            return;
        }

        foreach (DeliveryTracker::FIELDS as $field) {
            if ($entity->isNew() && in_array($entity->get($field), [null, ''], true)) {
                continue;
            }

            if ($entity->isAttributeChanged($field)) {
                throw new Conflict('TurboSMS delivery fields are managed by synchronization.');
            }
        }

        // Generic SMS callers save Failed after any sender exception, including an uncertain submission.
        if (!$entity->isNew() && $entity->getFetched('status') === Sms::STATUS_SENDING
            && $entity->get('status') === Sms::STATUS_FAILED
            && $entity->getFetched('turboSmsDeliveryStatus') === 'Unknown') {
            $entity->set('status', Sms::STATUS_SENDING);
        }
    }
}
