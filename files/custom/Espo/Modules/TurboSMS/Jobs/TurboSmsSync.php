<?php

declare(strict_types=1);

namespace Espo\Modules\TurboSMS\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\TurboSMS\Core\SyncService;

final class TurboSmsSync implements JobDataLess
{
    public function __construct(private SyncService $service)
    {}

    public function run(): void
    {
        $this->service->run();
    }
}
