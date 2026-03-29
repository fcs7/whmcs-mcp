<?php
// src/Whmcs/CompatContainer.php
namespace NtMcp\Whmcs;

use Psr\Container\ContainerInterface;

/**
 * PSR-11 container compatible with both v1 (no type hints) and v2 (typed).
 *
 * WHMCS bundles PSR Container v1 where get($id) has no type hint.
 * php-mcp/server's BasicContainer uses v2 signatures (string $id): mixed.
 * This class bridges the gap by omitting parameter type hints, making it
 * compatible with whichever version WHMCS autoloads first.
 */
class CompatContainer implements ContainerInterface
{
    private array $instances = [];

    /** @param string $id */
    public function get($id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!class_exists($id)) {
            throw new \RuntimeException("Class or entry '{$id}' not found.");
        }

        $ref = new \ReflectionClass($id);
        if (!$ref->isInstantiable()) {
            throw new \RuntimeException("Class '{$id}' is not instantiable.");
        }

        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            $instance = $ref->newInstance();
            $this->instances[$id] = $instance;
            return $instance;
        }

        // Auto-wire: resolve constructor parameters via type hints
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName)) {
                    $args[] = $this->get($typeName);
                    continue;
                }
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            throw new \RuntimeException(
                "Cannot resolve parameter '\${$param->getName()}' for '{$id}'."
            );
        }

        $instance = $ref->newInstanceArgs($args);
        $this->instances[$id] = $instance;

        return $instance;
    }

    /** @param string $id */
    public function has($id): bool
    {
        return isset($this->instances[$id]) || class_exists($id);
    }

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }
}
