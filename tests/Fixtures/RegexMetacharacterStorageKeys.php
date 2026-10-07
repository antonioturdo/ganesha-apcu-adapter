<?php

declare(strict_types=1);

namespace Zeusi\GaneshaApcuAdapter\Tests\Fixtures;

use Ackintosh\Ganesha\Storage\StorageKeysInterface;

/**
 * Storage keys made of regular expression metacharacters, to verify they are matched literally.
 */
final class RegexMetacharacterStorageKeys implements StorageKeysInterface
{
    public function prefix(): string
    {
        return 'app.circuit/^';
    }

    public function success(): string
    {
        return '$success';
    }

    public function failure(): string
    {
        return '(failure)';
    }

    public function rejection(): string
    {
        return '[rejection]';
    }

    public function lastFailureTime(): string
    {
        return '|last_failure_time';
    }

    public function status(): string
    {
        return '+status?';
    }
}
