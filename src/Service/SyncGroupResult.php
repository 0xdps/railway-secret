<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Outcome of a sync-group rotation.
 *
 * updated/failed/restored contain scope labels ("global" or a Railway service id),
 * never secret values.
 */
final class SyncGroupResult
{
    /**
     * @param list<string> $updated
     * @param list<string> $failed
     * @param list<string> $restored
     */
    public function __construct(
        public readonly bool $ok,
        public readonly bool $partial,
        public readonly array $updated,
        public readonly array $failed,
        public readonly array $restored,
    ) {}

    public function summary(): string
    {
        if ($this->ok) {
            return 'Sync group rotated';
        }
        if ($this->partial) {
            return 'Sync group rotation partially applied. Some services still have the new value.';
        }
        return 'Sync group rotation failed';
    }
}
