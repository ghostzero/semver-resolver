# Semver Resolver for PHP

Calculate an 'optimal' solution for a dependency tree using semantic versioning.

- Uses https://github.com/composer/semver
- Which implements http://semver.org/

> This package has unit tests with 100% code coverage.

## Usage

```
composer require ghostzero/semver-resolver
```

```
$versionRepository = // implement version resolver
$dependencyRepository = ...; // implement dependencies repository

$resolver = new SemverResolver([
    'test1' => '^0.1.0',
    'test2' => '0.1.0'
], $versionRepository, $dependencyRepository);

$resolved = $resolver->resolve();

$this->assertEquals([
    'test1' => '0.1.1',
    'test2' => '0.1.0'
], $resolved);
```

## Algorithm

The resolver works in passes. In each pass the following occurs:

1. Uncalculated dependencies are queued for calculation
1. Available versions are cached for dependencies that have not been cached yet
1. Max satisfying versions are calculated for queued dependencies
1. If constraints can't be met due to a version of a dependency fixed in an earlier pass then the version of the conflicting dependency will be backtracked to the next earlier version (by adding a new constraint), dropped from the current state of the calculation and requeued for calculation
1. Any dependencies of a requeued calculation will also be dropped and requeued
1. Calculated versions are then added to to a queue to update the state with their dependencies
1. Dependencies are cached for the calculated versions that have not yet been cached
1. The new dependencies are queued for recalculation after dropping the previous calculations and their dependencies
1. Already queued caclulations are filtered to ensure that any orphaned libraries do not get recalculated - the recursive dropping of libraries can result in already queued calculations no longer being valid/required
1. The next pass starts again at step 2

Passes continue until there are no longer any calculations queued

## Limitations

Although an attempt is made to calculate an 'optimal' solution by preferring the maximum satisfying versions according to semantic versioning rules, it is possible that the actual solution could be considered sub-optimal. The following limitations should be considered.

- When backtracking it is assumed that older versions of a library will have older dependencies
    - this means we choose to backtrack the libraries providing the upper constraints
    - if a library has reverted a version of a dependency due to some issue then it may be possible that a newer matching solution could be found by backtracking the library with the lower constraint
    - in such a case, however, it may well be undesirable to backtrack and the algorithm should avoid this
- The definition of optimal may not be clear, particularly if multiple solutions are available
    - The algiorithm does not consider possible alternative solutions and only returns the first it finds
    - the choice of libraries to backtrack is somewhat arbitrary, in that on each pass the first upper constraint found will be backtracked until a solution can be found
    - It may be preferable to backtrack differently (ie. choosing different libraries to backtrack or backtracking in a different order)

If a better solution is known it should be reflected by the user through pinned versions in the root dependencies