<?php

namespace AdamczykPiotr\DagWorkflows\Services;

use Illuminate\Support\Str;

/**
 * A dynamic dependency is a dependsOn entry with a trailing wildcard ("POI: *").
 * It gates the dependant on every OTHER task whose name starts with the prefix —
 * both tasks known at definition time and tasks spawned at runtime (a
 * ResolvableTask's children), which do not exist yet when the workflow is stored.
 *
 * The declaring task never matches its own wildcard, so an aggregate task may
 * live in the namespace it waits for ("POI: Aggregate" depending on "POI: *").
 *
 * "*" is reserved for this syntax and must not appear in task names.
 */
class DynamicDependenciesService {

    public const string RESERVED_CHARACTER = '*';


    /**
     * @param string $dependencyName
     * @return bool
     */
    public function isDynamic(string $dependencyName): bool {
        return Str::endsWith($dependencyName, self::RESERVED_CHARACTER);
    }


    /**
     * The task name prefix a dynamic dependency matches against.
     *
     * @param string $dependencyName
     * @return string
     */
    public function prefix(string $dependencyName): string {
        return Str::substr($dependencyName, 0, -Str::length(self::RESERVED_CHARACTER));
    }


    /**
     * @param string $dependencyName
     * @param string $taskName
     * @param string $declaringTaskName
     * @return bool
     */
    public function matches(string $dependencyName, string $taskName, string $declaringTaskName): bool {
        return $taskName !== $declaringTaskName
            && Str::startsWith($taskName, $this->prefix($dependencyName));
    }
}
