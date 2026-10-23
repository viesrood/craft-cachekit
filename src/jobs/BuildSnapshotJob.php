<?php

declare(strict_types=1);

namespace viesrood\cachekit\jobs;

use craft\queue\BaseJob;
use viesrood\cachekit\Plugin;

class BuildSnapshotJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->getSnapshot()->build();
    }

    protected function defaultDescription(): ?string
    {
        return 'CacheKit-noodkopie bouwen';
    }
}
