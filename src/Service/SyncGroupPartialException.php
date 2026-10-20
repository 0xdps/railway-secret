<?php

declare(strict_types=1);

namespace App\Service;

final class SyncGroupPartialException extends \RuntimeException
{
    public function __construct(public readonly SyncGroupResult $result)
    {
        parent::__construct($result->summary());
    }
}
