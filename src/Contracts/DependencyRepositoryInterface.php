<?php

namespace GhostZero\SemverResolver\Contracts;

use GhostZero\SemverResolver\Exceptions\DependencyNotFoundException;

interface DependencyRepositoryInterface
{
    /**
     * @throws DependencyNotFoundException
     */
    public function getDependencies(string $library, string $version): array;
}