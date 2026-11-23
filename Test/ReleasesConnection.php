<?php

namespace Kanboard\Plugin\Deed\Test;

use ReflectionObject;

/**
 * Drops the test's own properties after Base::tearDown. PHPUnit keeps every test object alive until the run ends,
 * so a property holding the PDO (or a service built on it) keeps the connection open, and Postgres then refuses
 * Base::setUp's DROP DATABASE ("being accessed by other users"). The container's services hold the PDO too and sit
 * in reference cycles (container -> plugin -> closures), so collect them before the next test drops the database.
 */
trait ReleasesConnection
{
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ((new ReflectionObject($this))->getProperties() as $property) {
            if (! $property->isStatic() && $property->getDeclaringClass()->getName() === static::class) {
                unset($this->{$property->getName()});
            }
        }
        gc_collect_cycles();
    }
}
