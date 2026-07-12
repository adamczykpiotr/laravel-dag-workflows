<?php

namespace AdamczykPiotr\DagWorkflows\Services\Support;

use Illuminate\Support\Str;

/**
 * A dynamic dependency is a dependsOn entry with a trailing wildcard ("Task name:*").
 * It gates the dependant on the base task ("Task name") AND on every task the
 * base task spawns at runtime (names prefixed "Task name:"), so a task can wait
 * for all children of a ResolvableTask that do not exist yet at definition time.
 *
 * "*" is reserved for this syntax and must not appear in task names.
 */
class DynamicDependencies {

    public const string RESERVED_CHARACTER = '*';
    public const string SUFFIX = ':*';


    /**
     * @param string $dependencyName
     * @return bool
     */
    public static function isDynamic(string $dependencyName): bool {
        return Str::endsWith($dependencyName, self::SUFFIX);
    }


    /**
     * The task name a dynamic dependency gates on upfront. Static dependency
     * names pass through unchanged.
     *
     * @param string $dependencyName
     * @return string
     */
    public static function baseTaskName(string $dependencyName): string {
        return self::isDynamic($dependencyName)
            ? Str::substr($dependencyName, 0, -Str::length(self::SUFFIX))
            : $dependencyName;
    }


    /**
     * The name prefix ("Task name:") the base task's spawned children share.
     *
     * @param string $dependencyName
     * @return string
     */
    public static function childPrefix(string $dependencyName): string {
        return self::baseTaskName($dependencyName) . ':';
    }
}
