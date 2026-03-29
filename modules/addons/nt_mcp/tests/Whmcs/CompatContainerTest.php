<?php
// tests/Whmcs/CompatContainerTest.php
namespace NtMcp\Tests\Whmcs;

use NtMcp\Whmcs\CompatContainer;
use PHPUnit\Framework\TestCase;

class CompatContainerTest extends TestCase
{
    public function test_set_and_get_returns_same_instance(): void
    {
        $container = new CompatContainer();
        $obj = new \stdClass();
        $obj->tag = 'original';

        $container->set('my.service', $obj);

        $this->assertSame($obj, $container->get('my.service'));
    }

    public function test_get_creates_zero_arg_class(): void
    {
        $container = new CompatContainer();

        $instance = $container->get(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $instance);
    }

    public function test_get_caches_instances(): void
    {
        $container = new CompatContainer();

        $first = $container->get(\stdClass::class);
        $second = $container->get(\stdClass::class);

        $this->assertSame($first, $second);
    }

    public function test_has_returns_true_for_registered(): void
    {
        $container = new CompatContainer();
        $container->set('foo', new \stdClass());

        $this->assertTrue($container->has('foo'));
    }

    public function test_has_returns_true_for_existing_class(): void
    {
        $container = new CompatContainer();

        $this->assertTrue($container->has(\stdClass::class));
    }

    public function test_has_returns_false_for_nonexistent(): void
    {
        $container = new CompatContainer();

        $this->assertFalse($container->has('NonExistent\\Foo'));
    }

    public function test_get_throws_for_unknown_class(): void
    {
        $container = new CompatContainer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $container->get('NonExistent\\Foo');
    }

    public function test_get_auto_wires_dependencies(): void
    {
        $container = new CompatContainer();

        // Register the dependency so auto-wiring can resolve it
        $dep = new CompatContainerTestDependency();
        $dep->value = 42;
        $container->set(CompatContainerTestDependency::class, $dep);

        $instance = $container->get(CompatContainerTestDependent::class);

        $this->assertInstanceOf(CompatContainerTestDependent::class, $instance);
        $this->assertSame($dep, $instance->dep);
    }

    public function test_get_throws_for_unresolvable_param(): void
    {
        $container = new CompatContainer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve');

        $container->get(CompatContainerTestUnresolvable::class);
    }
}

// --- Test fixture classes ---

class CompatContainerTestDependency
{
    public int $value = 0;
}

class CompatContainerTestDependent
{
    public function __construct(public readonly CompatContainerTestDependency $dep) {}
}

class CompatContainerTestUnresolvable
{
    /** @param mixed $requiredParam No type hint, no default — unresolvable */
    public function __construct($requiredParam) {}
}
