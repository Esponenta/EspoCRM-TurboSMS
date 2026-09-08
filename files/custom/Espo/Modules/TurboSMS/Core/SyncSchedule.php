<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Core;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueUtil;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Integration;
use Espo\Entities\Job;
use Espo\Entities\ScheduledJob;
use Espo\Modules\TurboSMS\Jobs\TurboSmsSync;
use Espo\ORM\EntityManager;

final class SyncSchedule
{
    public const JOB = 'TurboSmsSync';
    public const GROUP = 'turbosms-manual-sync';
    public const CRON = '*/5 * * * *';

    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private QueueUtil $queueUtil,
    ) {}

    public function reconcile(): void
    {
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'TurboSMS');
        $enabled = $integration?->get('enabled') && ($integration->get('turboSmsSyncEnabled') ?? true);
        $job = $this->entityManager->getRDBRepository(ScheduledJob::ENTITY_TYPE)->where(['job' => self::JOB])->findOne()
            ?? $this->entityManager->getNewEntity(ScheduledJob::ENTITY_TYPE);
        $job->set([
            'name' => 'Synchronize TurboSMS',
            'job' => self::JOB,
            'scheduling' => $integration?->get('turboSmsSyncCron') ?: self::CRON,
            'status' => $enabled ? 'Active' : 'Inactive',
            'isInternal' => false,
        ]);
        $this->entityManager->saveEntity($job, [SaveOption::SILENT => true]);
    }

    public function enqueue(): bool
    {
        return $this->entityManager->getTransactionManager()->run(function (): bool {
            $integration = $this->entityManager->getRDBRepository(Integration::ENTITY_TYPE)
                ->where(['id' => 'TurboSMS'])->forUpdate()->findOne();

            if (!$integration?->get('enabled')) {
                throw new ApiException('TurboSMS integration is not enabled.');
            }

            $manual = $this->entityManager->getRDBRepository(Job::ENTITY_TYPE)
                ->where(['group' => self::GROUP, 'status' => ['Pending', 'Ready', 'Running']])->findOne();
            $scheduled = $this->entityManager->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => self::JOB])->findOne();

            if ($manual || ($scheduled && $this->queueUtil->isScheduledJobRunning($scheduled->getId()))) {
                return false;
            }

            $this->jobSchedulerFactory->create()->setClassName(TurboSmsSync::class)->setGroup(self::GROUP)->schedule();

            return true;
        });
    }
}
