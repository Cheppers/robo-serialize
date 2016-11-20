<?php

namespace Cheppers\Robo\Serialize;

use Cheppers\Robo\Serialize\Task\SerializeTask;
use Robo\Collection\CollectionBuilder;

/**
 * Class SerializeTaskLoader.
 *
 * @package Cheppers\Robo\Serialize
 */
trait SerializeTaskLoader
{
    /**
     * @return SerializeTask|CollectionBuilder
     */
    protected function taskSerialize(array $options = [])
    {
        return $this->task(SerializeTask::class, $options);
    }
}
