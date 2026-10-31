<?php

namespace App\Services\Channels;

final class DisconnectionResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly bool $credentialsPurged,
        public readonly bool $unsubscribed,
        public readonly bool $configShared,
        public readonly array $warnings = [],
    ) {}
}
