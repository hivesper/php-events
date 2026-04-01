<?php

namespace Vesper\Tool\Event\Infrastructure;

use InvalidArgumentException;
use Override;
use ReflectionFunction;
use ReflectionNamedType;
use Tcds\Io\Jackson\ArrayObjectMapper;
use Vesper\Tool\Event\EventHydrator;

readonly class JacksonHydrator implements EventHydrator
{
    private ArrayObjectMapper $mapper;

    public function __construct()
    {
        $this->mapper = new ArrayObjectMapper();
    }

    #[Override]
    public function hydrate(string $name, array $payload, callable $subscriber): object
    {
        $type = $this->firstParameterType($subscriber);

        if ($type === null) {
            return (object) $payload;
        }

        /** @var object */
        return $this->mapper->readValue($type, $payload);
    }

    /** @return class-string|null */
    private function firstParameterType(callable $subscriber): ?string
    {
        $parameters = new ReflectionFunction($subscriber(...))->getParameters();

        if (empty($parameters)) {
            return null;
        }

        $type = $parameters[0]->getType();

        // Untyped first argument or `object` type hint — fall back to stdClass.
        if (!$type instanceof ReflectionNamedType || $type->getName() === 'object') {
            return null;
        }

        // Any other built-in (string, int, float, bool, array, …) is not allowed.
        if ($type->isBuiltin()) {
            throw new InvalidArgumentException(
                "Subscriber's first argument must be typed as object or a class, got '{$type->getName()}'.",
            );
        }

        /** @var class-string */
        return $type->getName();
    }
}
