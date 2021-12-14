<?php

namespace GhostZero\SemverResolver\Contracts;

use GhostZero\SemverResolver\Exceptions\DependencyNotFoundException;

interface VersionRepositoryInterface
{
    /**
     * @throws DependencyNotFoundException
     */
    public function getVersions(string $library): array;
}