<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the development database: tests reset their database, so they must only ever run on the
 * in-memory SQLite database forced in phpunit.xml, whatever the machine's environment says.
 */
class TestDatabaseIsIsolatedTest extends TestCase
{
    public function test_tests_always_use_the_in_memory_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
