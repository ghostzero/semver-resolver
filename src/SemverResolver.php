<?php

namespace GhostZero\SemverResolver;

use Composer\Semver\Semver;
use GhostZero\SemverResolver\Contracts\DependencyRepositoryInterface;
use GhostZero\SemverResolver\Contracts\VersionRepositoryInterface;
use GhostZero\SemverResolver\Exceptions\DependencyException;
use GhostZero\SemverResolver\Support\Arr;
use Ramsey\Uuid\Uuid;

class SemverResolver
{
    protected VersionRepositoryInterface $versionRepository;
    protected DependencyRepositoryInterface $dependencyRepository;
    protected array $state;
    protected string $root;
    private array $queuedCalculations;
    private array $queuedConstraintUpdates = [];
    private array $cachedVersions = [];
    private array $cachedDependencies = [];

    /**
     * @param array<string,string> $dependencies
     */
    public function __construct(
        array                         $dependencies,
        VersionRepositoryInterface    $versionRepository,
        DependencyRepositoryInterface $dependencyRepository
    )
    {
        $this->versionRepository = $versionRepository;
        $this->dependencyRepository = $dependencyRepository;
        $this->root = (string)Uuid::uuid4();
        $this->state = [
            $this->root => [
                'dependencies' => Arr::mapValues($dependencies, function ($range) {
                    return [
                        'range' => $range,
                    ];
                }),
            ],
        ];
        $this->queuedCalculations = array_keys($dependencies);
    }

    /**
     * @throws Exceptions\DependencyNotFoundException
     */
    protected function cacheVersions(): void
    {
        $librariesToCache = Arr::difference(
            $this->queuedCalculations, array_keys($this->cachedVersions)
        );

        $versionsArray = array_map(function (string $library) {
            return $this->versionRepository->getVersions($library);
        }, $librariesToCache);

        foreach ($versionsArray as $index => $versions) {
            $this->cachedVersions[$librariesToCache[$index]] =
                Semver::rsort(array_slice($versions, 0));
        }
    }

    /**
     * @throws DependencyException
     */
    protected function maxSatisfying(string $library): ?string
    {
        $versions = $this->cachedVersions[$library];
        $dependencyLibraries = [];
        // first collect all the constraints and the max versions
        // satisfying them, keyed by the parent that adds the constraint
        Arr::forOwn($this->state, function ($libraryState, $parent) use ($library, $versions, &$dependencyLibraries) {
            $dependencies = $libraryState['dependencies'] ?? []; // todo maybe not nullable?
            if (!empty($dependencies)) { // is set
                $dependencyLibrary = $dependencies[$library] ?? []; // todo maybe not nullable?
                if (!empty($dependencyLibrary)) { // is set
                    if (empty($dependencyLibrary['maxSatisfying'])) { // maxSatisfying is not set
                        $range = $dependencyLibrary['range'];
                        $maxSatisfying = $this->semverMaxSatisfying($versions, $range);
                        if ($maxSatisfying === null) {
                            $backtrackedDueTo = $dependencyLibrary['backtrackedDueTo'] ?? null;
                            $constrainingLibrary = 'root';
                            $version = $libraryState['version'] ?? null;
                            if ($version) {
                                $constrainingLibrary = "${parent}@${version}";
                            }
                            if ($backtrackedDueTo) {
                                throw new DependencyException(
                                    'Unable to satisfy backtracked version constraint: ' .
                                    "${library}@${range} from " .
                                    "${constrainingLibrary} due to shared " .
                                    "constraint on ${backtrackedDueTo}"
                                );
                            } else {
                                throw new DependencyException(
                                    'Unable to satisfy version constraint: ' .
                                    "${library}@${range} from " .
                                    $constrainingLibrary
                                );
                            }
                        }
                        // set maxSatisfying to $maxSatisfying;
                        // fixme maybe not saved due copy by value
                        $dependencyLibrary['maxSatisfying'] = $maxSatisfying;
                    }
                    $dependencyLibraries[$parent] = $dependencyLibrary;
                }
            }
        });

        // next scan the max versions to find the minimum
        $lowestMaxSatisfying = null;
        Arr::forOwn($dependencyLibraries, function ($dependencyLibrary, $parent) use (&$lowestMaxSatisfying) {
            $maxSatisfying = $dependencyLibrary['maxSatisfying'];
            if ($lowestMaxSatisfying === null) {
                $lowestMaxSatisfying = [
                    'parent' => $parent,
                    'version' => $maxSatisfying,
                ];
            }

            // original was $maxSatisfying < $lowestMaxSatisfying['version']
            //if (Comparator::lessThan($maxSatisfying, $lowestMaxSatisfying['version'])) {
            if ($maxSatisfying < $lowestMaxSatisfying['version']) {
                $lowestMaxSatisfying['parent'] = $parent;
                $lowestMaxSatisfying['version'] = $maxSatisfying;
            }
        });

        // then check if that minimum satisfies the other constraints
        // if a conflicting constraint is found then we have no version and should
        // drop and requeue the library version that adds the conflict, with
        // a new constraint to check for an earlier version of it
        $constrainingParent = $lowestMaxSatisfying['parent'] ?? null;
        $version = $lowestMaxSatisfying['version'] ?? null;
        $resolutionFound = true;
        Arr::forOwn($dependencyLibraries, function ($dependencyLibrary, $parent) use ($library, $constrainingParent, $version, &$resolutionFound) {
            if ($parent !== $constrainingParent) {
                $range = $dependencyLibrary['range'];

                if (!Semver::satisfies($version, $range)) {
                    // check if parent is root as root
                    // cannot be backtracked
                    $constrainingState = $this->state[$constrainingParent];
                    $constrainedState = $this->state[$parent];
                    $constrainedStateVersion = $constrainedState['version'] ?? null;
                    if (!$constrainedStateVersion) {
                        throw new DependencyException(
                            'Unable to satisfy version constraint: ' .
                            "${library}@${range} from root due to " .
                            'shared constraint from ' .
                            "${constrainingParent}@${constrainingState['version']}"
                        );
                    }

                    // constraint cannot be met so add a new constraint
                    // to the parent providing the lowest version for this
                    // conflicting parent to backtrack to the next lowest version
                    $this->state[$constrainingParent]['dependencies'][$parent] = [
                        'range' => sprintf('<%s', $constrainedStateVersion),
                        'backtrackedDueTo' => $library
                    ];

                    // drop old data for dependency if we have it
                    // already as it should not
                    // be used in calculations anymore
                    $this->dropLibrary($parent);

                    // queue dependency for recalculation
                    // as a constraint has been dropped
                    // but it may still be a dependency
                    // of another library still in the tree
                    $this->queuedCalculations[] = $parent;
                    $resolutionFound = false;
                    return $resolutionFound;
                }
            }
        });

        if ($resolutionFound) {
            return $version;
        }

        return null;
    }

    protected function semverMaxSatisfying(array $versions, string $range): ?string
    {
        return Semver::rsort(Semver::satisfiedBy($versions, $range))[0] ?? null;
    }

    /**
     * @throws DependencyException
     */
    private function resolveVersions()
    {
        $queuedCalculations = $this->queuedCalculations;
        $nextQueuedCalculations = $this->queuedCalculations = [];
        foreach ($queuedCalculations as $library) {
            // don't calculate now if the library was already requeued
            // due to backtracking - it may have been orphaned
            // and anyway tracking the state gets complicated
            if (!Arr::includes($nextQueuedCalculations, $library)) {
                $version = $this->maxSatisfying($library);
                if ($version) {
                    $this->state[$library] = [
                        'version' => $version,
                    ];
                    $this->queuedConstraintUpdates[] = $library;
                }
            }
        }

        // clean up the queued constraint updates
        // as some of the libraries may no longer
        // even be in dependencies
        $this->cleanQueuedConstraintUpdates();
    }

    protected function cleanQueuedConstraintUpdates(): void
    {
        $knownLibraries = array_keys($this->state);
        // we only want to look up dependencies for
        // libraries still in the state
        $this->queuedConstraintUpdates = Arr::intersection(
            $this->queuedConstraintUpdates,
            $knownLibraries
        );
    }

    /**
     * @throws Exceptions\DependencyNotFoundException
     */
    protected function cacheDependencies(): void
    {
        $dependenciesToCache = array_filter($this->queuedConstraintUpdates, function (string $library) {
            $version = $this->state[$library]['version'];
            return empty($this->cachedDependencies[$library][$version]);
        });

        $dependenciesArray = array_map(function (string $library) {
            return $this->dependencyRepository->getDependencies($library, $this->state[$library]['version']);
        }, $dependenciesToCache);

        foreach ($dependenciesArray as $index => $dependencies) {
            $library = $dependenciesToCache[$index];
            $this->cachedDependencies[$library][$this->state[$library]['version']] =
                Arr::mapValues($dependencies, fn($range) => ['range' => $range]);
        }
    }

    protected function dropLibrary(string $library): void
    {
        if (isset($this->state[$library])) {
            $dependencies = $this->state[$library]['dependencies'] ?? []; // fallback required for if statement
            // remove from state
            unset($this->state[$library]);
            if (!empty($dependencies)) {
                $dependencyKeys = array_keys($dependencies);
                foreach ($dependencyKeys as $dependency) {
                    // drop old data for dependency if we have it
                    // already as it should not
                    // be used in calculations anymore
                    $this->dropLibrary($dependency);
                    // queue dependency for recalculation
                    // as a constraint has been dropped
                    // but it may still be a dependency
                    // of another library still in the tree
                    $this->queuedCalculations[] = $dependency;
                }
            }

        }
    }

    protected function refillQueues(): void
    {
        $queuedConstraintUpdates = Arr::uniq($this->queuedConstraintUpdates);
        $this->queuedConstraintUpdates = [];
        foreach ($queuedConstraintUpdates as $library) {
            $this->updateConstraints($library);
        }

        // clean up the queued calculations
        // as some of the libraries may no longer
        // even be in dependencies
        $this->cleanQueuedCalculations();
    }

    protected function updateConstraints(string $library): void
    {
        // check if this library is still in the state.
        // it may already have been dropped in an earlier
        // update, in which case the information we would
        // apply now is invalid anyway
        if (isset($this->state[$library])) {
            $version = $this->state[$library]['version'];
            $dependencies = $this->cachedDependencies[$library][$version];
            $this->state[$library]['dependencies'] = $dependencies;
            // We don't need to worry about the possibility that there were already
            // dependencies attached to the library. It should
            // never happen as the only way to get into the update
            // queue is from the calculation queue and the only way
            // into the caclulation queue is on initialisation or
            // immediately after being dropped from the state. Thus
            // all these dependency constraints are new and none
            // will be dropped.
            $dependencyKeys = array_keys($dependencies);
            foreach ($dependencyKeys as $dependency) {
                // drop old data for dependency if we have it
                // already as it should not
                // be used in calculations anymore
                $this->dropLibrary($dependency);
                // queue dependency for recalculation
                // as a constraint has been dropped
                // but it may still be a dependency
                // of another library still in the tree
                $this->queuedCalculations[] = $dependency;
            }
        }
    }

    protected function cleanQueuedCalculations(): void
    {
        $knownLibraries = array_keys($this->state);
        foreach ($knownLibraries as $library) {
            $dependencies = $this->state[$library]['dependencies'];
            // dependencies will always be populated
            // here because we just finished updating
            // from the queued constraints - if it isn't
            // then something probably changed around
            // the refillQueues/updateConstraints functions
            $knownLibraries = Arr::union($knownLibraries, array_keys($dependencies));
        }
        $this->queuedCalculations = Arr::intersection($this->queuedCalculations, $knownLibraries);
    }

    /**
     * @throws DependencyException
     */
    protected function recurse(): void
    {
        if (!empty($this->queuedCalculations)) {
            $this->start();
        }
    }

    /**
     * @throws DependencyException
     */
    protected function start(): void
    {
        $this->cacheVersions();
        $this->resolveVersions();
        $this->cacheDependencies();
        $this->refillQueues();
        $this->recurse();
    }

    /**
     * @throws DependencyException
     */
    public function resolve()
    {
        $this->start();
        unset($this->state[$this->root]);
        return Arr::mapValues($this->state, fn($value) => $value['version']);
    }
}