<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Classes\Rebuild\Actions;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Preferences;
use Espo\Entities\Sms;
use Espo\Entities\User;
use Espo\Modules\TurboSMS\Reports\CurrentBalance;
use Espo\ORM\EntityManager;
use stdClass;

final class ConfigureBalanceReport implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {
    }

    public function process(): void
    {
        if (!$this->metadata->get(['scopes', 'Report', 'entity'], false)) {
            return;
        }

        $reportIds = [];

        foreach ($this->entityManager->getRDBRepository('Report')->where([
            'internalClassName' => CurrentBalance::INTERNAL_NAME,
        ])->find() as $report) {
            $reportIds[] = $report->getId();

            if (
                $report->get('isInternal') === true &&
                $report->get('entityType') === Sms::ENTITY_TYPE &&
                $report->get('type') === 'Grid' &&
                $report->get('depth') === 0 &&
                $report->get('columns') === [CurrentBalance::COLUMN] &&
                ($report->get('groupBy') ?? []) === []
            ) {
                continue;
            }

            $report->set([
                'isInternal' => true,
                'entityType' => Sms::ENTITY_TYPE,
                'type' => 'Grid',
                'depth' => 0,
                'columns' => [CurrentBalance::COLUMN],
                'groupBy' => [],
            ]);
            $this->entityManager->saveEntity($report, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
            ]);
        }

        if ($reportIds === []) {
            return;
        }

        foreach ($this->entityManager->getRDBRepositoryByClass(User::class)->where([
            'isActive' => true,
        ])->find() as $user) {
            $preferences = $this->entityManager->getEntityById(
                Preferences::ENTITY_TYPE,
                $user->getId(),
            );

            if (!$preferences) {
                continue;
            }

            $options = $preferences->get('dashletsOptions');

            if (!$options instanceof stdClass || !$this->configureDashlets($options, $reportIds)) {
                continue;
            }

            $preferences->set('dashletsOptions', $options);
            $this->entityManager->saveEntity($preferences, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
            ]);
        }
    }

    /** @param string[] $reportIds */
    private function configureDashlets(stdClass $options, array $reportIds): bool
    {
        $changed = false;

        foreach (get_object_vars($options) as $option) {
            if (!$option instanceof stdClass || !in_array($option->reportId ?? null, $reportIds, true)) {
                continue;
            }

            foreach ([
                'type' => 'Grid',
                'entityType' => Sms::ENTITY_TYPE,
                'column' => CurrentBalance::COLUMN,
                'displayType' => 'Total',
                'displayOnlyCount' => true,
                'displayTotal' => true,
                'useSiMultiplier' => false,
            ] as $name => $value) {
                if (($option->$name ?? null) !== $value) {
                    $option->$name = $value;
                    $changed = true;
                }
            }
        }

        return $changed;
    }
}
