<?php

namespace Tests\Unit;

use GhostZero\SemverResolver\Contracts\DependencyRepositoryInterface;
use GhostZero\SemverResolver\Contracts\VersionRepositoryInterface;
use GhostZero\SemverResolver\Exceptions\DependencyException;
use GhostZero\SemverResolver\Exceptions\DependencyNotFoundException;
use GhostZero\SemverResolver\SemverResolver;
use Tests\TestCase;

class SemverResolverTest extends TestCase
{
    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWith1LevelOfConstraintsThatCanBeResolvedShouldSuccessfullyResolveTheVersionConstraints()
    {
        $repository = new Repository('one-level-of-constraints');

        $resolver = new SemverResolver([
            'test1' => '^0.1.0',
            'test2' => '0.1.0'
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.1',
            'test2' => '0.1.0'
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @covers \GhostZero\SemverResolver\Exceptions\DependencyException
     */
    public function testWithConstraintsThatCannotBeResolvedShouldFailWithAnError()
    {
        $repository = new Repository('one-level-of-constraints');

        $resolver = new SemverResolver([
            'test1' => '^0.1.0',
            'test2' => '^0.2.0'
        ], $repository, $repository);

        $this->expectException(DependencyException::class);
        $this->expectErrorMessage('Unable to satisfy version constraint: test2@^0.2.0 from root');

        $resolver->resolve();
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @covers \GhostZero\SemverResolver\Exceptions\DependencyException
     * @covers \GhostZero\SemverResolver\Exceptions\DependencyNotFoundException
     * @throws DependencyException
     */
    public function testWithAnUnknownLibraryShouldFailWithAnError()
    {
        $repository = new Repository('one-level-of-constraints');

        $resolver = new SemverResolver([
            'test1' => '^0.1.0',
            'test9' => '0.1.0'
        ], $repository, $repository);

        $this->expectException(DependencyNotFoundException::class);
        $this->expectErrorMessage('No such library: test9');

        $resolver->resolve();
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWithEasilyResolvableSubConstraintsShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('two-levels-of-constraints');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0'
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.1',
            'test2' => '0.1.1'
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWithOverlappingConstraintsShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('overlapping-constraints');

        $resolver = new SemverResolver([
            'test3' => '0.1.1',
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.1',
            'test2' => '0.1.1',
            'test3' => '0.1.1',
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWithSubConstraintsThatResultInRecalculationsShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('overriding-constraints');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0',
            'test4' => '0.1.0',
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.0',
            'test2' => '0.1.0',
            'test3' => '0.1.0',
            'test4' => '0.1.0',
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWithRecalculationsBeforeDependenciesAreLoadedShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('fast-overriding-constraints');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0',
            'test4' => '0.1.0',
            'test6' => '^0.1.0',
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.0',
            'test2' => '0.1.0',
            'test3' => '0.1.0',
            'test4' => '0.1.0',
            'test5' => '0.1.0',
            'test6' => '0.1.0',
            'test7' => '0.1.0',
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWithConstraintsThatRequireBacktrackingShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('backtracking-constraints');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0',
            'test3' => '0.1.0',
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.0',
            'test2' => '0.1.0',
            'test3' => '0.1.0',
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @throws DependencyException
     */
    public function testWhenRequeuingAlreadyQueuedCalculationsShouldSuccessfullyResolveTheVersionConstraints(): void
    {
        $repository = new Repository('requeuing-queued-calculations');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0',
            'test3' => '0.1.0',
        ], $repository, $repository);

        $resolved = $resolver->resolve();

        $this->assertEquals([
            'test1' => '0.1.0',
            'test2' => '0.1.0',
            'test3' => '0.1.0',
            'test4' => '0.1.1',
        ], $resolved);
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @covers \GhostZero\SemverResolver\Exceptions\DependencyException
     */
    public function testWithBacktrackingButStillCannotBeResolvedShouldBeRejected(): void
    {
        $repository = new Repository('backtracking-impossible-constraints');

        $resolver = new SemverResolver([
            'test2' => '^0.1.0',
            'test3' => '0.1.0',
        ], $repository, $repository);

        $this->expectException(DependencyException::class);
        $this->expectErrorMessage(
            'Unable to satisfy backtracked version constraint: ' .
            'test2@<0.1.0 from test3@0.1.0 due to shared ' .
            'constraint on test1'
        );

        $resolver->resolve();
    }

    /**
     * @covers \GhostZero\SemverResolver\SemverResolver
     * @covers \GhostZero\SemverResolver\Support\Arr
     * @covers \GhostZero\SemverResolver\Exceptions\DependencyException
     */
    public function testWhenTheRootWouldNeedToBeBacktrackedShouldBeRejected(): void
    {
        $repository = new Repository('backtracking-impossible-constraints');

        $resolver = new SemverResolver([
            'test2' => '0.1.1',
            'test3' => '0.1.0',
        ], $repository, $repository);

        $this->expectException(DependencyException::class);
        $this->expectErrorMessage(
            'Unable to satisfy version constraint: test2@0.1.1 ' .
            'from root due to shared constraint from test3@0.1.0'
        );

        $resolver->resolve();
    }
}

class Repository implements VersionRepositoryInterface, DependencyRepositoryInterface
{
    private array $repository;

    public function __construct($name)
    {
        $this->repository = json_decode(
            file_get_contents(sprintf('%s/../repositories/%s.json', __DIR__, $name)),
            true
        );
    }

    /**
     * @inheritdoc
     */
    public function getVersions(string $library): array
    {
        if (!isset($this->repository[$library])) {
            throw new DependencyNotFoundException(sprintf('No such library: %s', $library));
        }

        return array_keys($this->repository[$library]);
    }

    /**
     * @inheritdoc
     */
    public function getDependencies(string $library, string $version): array
    {
        if (!isset($this->repository[$library][$version])) {
            throw new DependencyNotFoundException(sprintf('No such library: %s', $library));
        }

        return $this->repository[$library][$version];
    }
}