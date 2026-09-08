<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Classes\Rebuild\Actions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\TurboSMS\Core\SyncSchedule;

final class ConfigureSync implements RebuildAction
{
    public function __construct(private SyncSchedule $schedule)
    {}

    public function process(): void
    {
        $this->schedule->reconcile();
    }
}
