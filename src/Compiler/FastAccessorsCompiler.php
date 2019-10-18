<?php

/*
 * This file is part of exsyst/orm-accelerator-bundle.
 *
 * Copyright (C) EXSyst
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EXSyst\ORMAcceleratorBundle\Compiler;

use Doctrine\ORM\Mapping\ReflectionEmbeddedProperty;
use EXSyst\DynamicClassGenerationBundle\Compiler\ClassGeneratorInterface;
use EXSyst\DynamicClassGenerationBundle\Compiler\ResolvedClassInfo;
use EXSyst\DynamicClassGenerationBundle\Helper\StreamWriter;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Filesystem\Exception\IOException;

class FastAccessorsCompiler implements ClassGeneratorInterface
{
    /** @var ManagerRegistry */
    private $doctrine;

    /** @var \ReflectionProperty|null */
    private static $embeddedParentProperty;

    /** @var \ReflectionProperty|null */
    private static $embeddedChildProperty;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    private static function getEmbeddedParentProperty(): \ReflectionProperty
    {
        if (!isset(self::$embeddedParentProperty)) {
            self::$embeddedParentProperty = new \ReflectionProperty(ReflectionEmbeddedProperty::class, 'parentProperty');
            self::$embeddedParentProperty->setAccessible(true);
        }

        return self::$embeddedParentProperty;
    }

    private static function getEmbeddedChildProperty(): \ReflectionProperty
    {
        if (!isset(self::$embeddedChildProperty)) {
            self::$embeddedChildProperty = new \ReflectionProperty(ReflectionEmbeddedProperty::class, 'childProperty');
            self::$embeddedChildProperty->setAccessible(true);
        }

        return self::$embeddedChildProperty;
    }

    private static function getPropertyChain(\ReflectionProperty $property): array
    {
        if (!$property instanceof ReflectionEmbeddedProperty) {
            return [[$property->getDeclaringClass()->getName(), $property->getName()]];
        }

        return \array_merge(
            self::getPropertyChain(self::getEmbeddedParentProperty()->getValue($property)),
            self::getPropertyChain(self::getEmbeddedChildProperty()->getValue($property)));
    }

    public function generate(ResolvedClassInfo $class): bool
    {
        $manager = $this->doctrine->getManagerForClass($class->getRest());
        if (null === $manager) {
            return false;
        }

        $metadata = $manager->getClassMetadata($class->getRest());
        $fd = \fopen($class->getPath(), 'wb');
        if (false === $fd) {
            throw new IOException('Cannot open temporary file to compile class '.$class->getClass());
        }
        try {
            $writer = new StreamWriter($fd);

            $nsPos = \strrpos($class->getClass(), '\\');
            $writer
                ->printfln('<?php')
                ->printfln()
                ->printfln('namespace %s;', \substr($class->getClass(), 0, $nsPos))
                ->printfln()
                ->printfln('use Doctrine\Instantiator\Instantiator;')
                ->printfln()
                ->printfln('class %s', \substr($class->getClass(), $nsPos + 1))
                ->printfln('{')
                ->indent()
            ;

            $chains = self::getPropertyChains($metadata->reflFields);
            self::emitBody($writer, $chains);

            $writer
                ->outdent()
                ->printfln('}')
            ;
        } finally {
            \fclose($fd);
        }

        return true;
    }

    private static function getPropertyChains(array $reflFields): array
    {
        return \array_map([self::class, 'getPropertyChain'], $reflFields);
    }

    private static function serializeChain(array $chain): string
    {
        return \implode("\0", \array_merge(...$chain));
    }

    private static function emitGetter(StreamWriter $writer, array &$scope, array $chain): string
    {
        $key = "get\0".self::serializeChain($chain);
        if (isset($scope[$key])) {
            return $scope[$key];
        }
        $last = \array_pop($chain);
        $previous = empty($chain) ? null : self::emitGetter($writer, $scope, $chain);
        $id = 'get'.\count($scope).(empty($chain) ? '' : '_'.\implode('_', \array_column($chain, 1))).'_'.$last[1];
        if (null !== $previous) {
            $writer->printfln('$%s = (function (\\%s $object) use ($%s) {', $id, ($chain[0] ?? $last)[0], $previous);
        } else {
            $writer->printfln('$%s = (function (\\%s $object) {', $id, ($chain[0] ?? $last)[0]);
        }
        $writer->indent();
        if (null !== $previous) {
            $writer
                ->printfln('$previous = $%s($object);', $previous)
                ->printfln('if (null === $previous) {')
                ->indent()
                ->printfln('return null;')
                ->outdent()
                ->printfln('}')
                ->printfln('return $previous->%s;', $last[1])
            ;
        } else {
            $writer->printfln('return $object->%s;', $last[1]);
        }
        $writer
            ->outdent()
            ->printfln('})->bindTo(null, \\%s::class);', $last[0])
            ->printfln()
        ;
        $scope[$key] = $id;

        return $id;
    }

    private static function emitSetter(StreamWriter $writer, array &$scope, array $chain): string
    {
        $key = "set\0".self::serializeChain($chain);
        if (isset($scope[$key])) {
            return $scope[$key];
        }
        $last = \array_pop($chain);
        $previousGetter = empty($chain) ? null : self::emitGetter($writer, $scope, $chain);
        $previousSetter = empty($chain) ? null : self::emitSetter($writer, $scope, $chain);
        $id = 'set'.\count($scope).(empty($chain) ? '' : '_'.\implode('_', \array_column($chain, 1))).'_'.$last[1];
        if (null !== $previousGetter) {
            $writer->printfln('$%s = (function (\\%s $object, $value) use ($instantiator, $%s, $%s): void {', $id, ($chain[0] ?? $last)[0], $previousGetter, $previousSetter);
        } else {
            $writer->printfln('$%s = (function (\\%s $object, $value): void {', $id, ($chain[0] ?? $last)[0]);
        }
        $writer->indent();
        if (null !== $previousGetter) {
            $writer
                ->printfln('$previous = $%s($object);', $previousGetter)
                ->printfln('if (null === $previous) {')
                ->indent()
                ->printfln('$previous = $instantiator->instantiate(\\%s::class);', $last[0])
                ->printfln('$%s($object, $previous);', $previousSetter)
                ->outdent()
                ->printfln('}')
                ->printfln('$previous->%s = $value;', $last[1])
            ;
        } else {
            $writer->printfln('$object->%s = $value;', $last[1]);
        }
        $writer
            ->outdent()
            ->printfln('})->bindTo(null, \\%s::class);', $last[0])
            ->printfln()
        ;
        $scope[$key] = $id;

        return $id;
    }

    private static function markParentChains(array &$chains): void
    {
        $classes = [];
        foreach ($chains as $chain) {
            $classes[$chain[0][0]] = true;
        }
        $classes = \array_keys($classes);
        $nClasses = \count($classes);
        if (1 === $nClasses) {
            return;
        }
        foreach ($classes as $i => $class) {
            foreach ($classes as $class2) {
                if ($class !== $class2 && \is_a($class2, $class, true)) {
                    unset($classes[$i]);
                    break;
                }
            }
        }
        $childmostClass = \reset($classes);
        foreach ($chains as &$chain) {
            if ($chain[0][0] !== $childmostClass) {
                \array_unshift($chain, [$childmostClass, '*']);
            }
        }
    }

    private static function extractCompoundChains(array &$chains): array
    {
        $compoundChains = [];
        foreach ($chains as $field => $chain) {
            if (\count($chain) > 1) {
                $first = \array_shift($chain);
                $key = $first[0]."\0".$first[1];
                if (!isset($compoundChains[$key])) {
                    $compoundChains[$key] = [$first, []];
                }
                $compoundChains[$key][1][$field] = $chain;
                unset($chains[$field]);
            }
        }

        return $compoundChains;
    }

    private static function emitBatchGetter(StreamWriter $writer, array &$scope, array $previousChain, array $chains): string
    {
        $key = 'bget'.(empty($previousChain) ? '' : "\0".self::serializeChain($previousChain));
        if (isset($scope[$key])) {
            return $scope[$key];
        }
        if (empty($chains)) {
            $id = 'bget'.\count($scope);
            $writer
                ->printfln('$%s = function ($object, array $fields): array {', $id)
                ->indent()
                ->printfln('return [];')
                ->outdent()
                ->printfln('};')
                ->printfln()
            ;

            $scope[$key] = $id;

            return $id;
        }
        self::markParentChains($chains);
        $class = \reset($chains)[0][0];
        $compoundChains = self::extractCompoundChains($chains);
        $ccGetters = [];
        foreach ($compoundChains as $ccKey => $compoundChain) {
            $subChains = \array_pop($compoundChain);

            $ccGetters[$ccKey] = self::emitBatchGetter($writer, $scope, \array_merge($previousChain, $compoundChain), $subChains);
        }
        $id = 'bget'.\count($scope);
        if (empty($ccGetters)) {
            $writer->printfln('$%s = (function (\\%s $object, array $fields): array {', $id, $class);
        } else {
            $writer->printfln('$%s = (function (\\%s $object, array $fields) use ($%s): array {', $id, $class, \implode(', $', $ccGetters));
        }
        $writer->indent();
        if (isset($compoundChains[$class."\0*"])) {
            $writer->printfln('$values = $%s($object, $fields);', $ccGetters[$class."\0*"]);
            unset($compoundChains[$class."\0*"]);
        } else {
            $writer->printfln('$values = [];');
        }
        foreach ($chains as $field => $chain) {
            $writer
                ->printfln()
                ->printfln('if (isset($fields[%s])) {', \var_export($field, true))
                ->indent()
                ->printfln('$values[%s] = $object->%s;', \var_export($field, true), $chain[0][1])
                ->outdent()
                ->printfln('}')
            ;
        }
        foreach ($compoundChains as $ccKey => $compoundChain) {
            $writer
                ->printfln()
                ->printfln('if (%s) {', \implode(' || ', \array_map(function (string $field): string {
                    return \sprintf('isset($fields[%s])', \var_export($field, true));
                }, \array_keys($compoundChain[1]))))
                ->indent()
                ->printfln('$subObject = $object->%s;', $compoundChain[0][1])
                ->printfln('if (null !== $subObject) {')
                ->indent()
                ->printfln('$values += $%s($subObject, $fields);', $ccGetters[$ccKey])
                ->outdent()
                ->printfln('} else {')
                ->indent()
            ;
            foreach ($compoundChain[1] as $field => $subChain) {
                $writer
                    ->printfln('if (isset($fields[%s])) {', \var_export($field, true))
                    ->indent()
                    ->printfln('$values[%s] = null;', \var_export($field, true))
                    ->outdent()
                    ->printfln('}')
                ;
            }
            $writer
                ->outdent()
                ->printfln('}')
                ->outdent()
                ->printfln('}')
            ;
        }
        $writer
            ->printfln()
            ->printfln('return $values;')
            ->outdent()
            ->printfln('})->bindTo(null, \\%s::class);', $class)
            ->printfln()
        ;

        $scope[$key] = $id;

        return $id;
    }

    private static function emitBatchSetter(StreamWriter $writer, array &$scope, array $previousChain, array $chains): string
    {
        $key = 'bset'.(empty($previousChain) ? '' : "\0".self::serializeChain($previousChain));
        if (isset($scope[$key])) {
            return $scope[$key];
        }
        if (empty($chains)) {
            $id = 'bset'.\count($scope);
            $writer
                ->printfln('$%s = function ($object, array $fields, ?array $definedFields = null): void {', $id)
                ->printfln('};')
            ;

            $scope[$key] = $id;

            return $id;
        }
        self::markParentChains($chains);
        $class = \reset($chains)[0][0];
        $compoundChains = self::extractCompoundChains($chains);
        $ccSetters = [];
        foreach ($compoundChains as $ccKey => $compoundChain) {
            $subChains = \array_pop($compoundChain);

            $ccSetters[$ccKey] = self::emitBatchSetter($writer, $scope, \array_merge($previousChain, $compoundChain), $subChains);
        }
        $id = 'bset'.\count($scope);
        if (empty($ccSetters)) {
            $writer->printfln('$%s = (function (\\%s $object, array $fields, ?array $definedFields = null): void {', $id, $class);
        } elseif (1 === \count($ccSetters) && isset($ccSetters[$class."\0*"])) {
            $writer->printfln('$%s = (function (\\%s $object, array $fields, ?array $definedFields = null) use ($%s): void {', $id, $class, \implode(', $', $ccSetters));
        } else {
            $writer->printfln('$%s = (function (\\%s $object, array $fields, ?array $definedFields = null) use ($instantiator, $%s): void {', $id, $class, \implode(', $', $ccSetters));
        }
        $writer
            ->indent()
            ->printfln('if (null === $definedFields) {')
            ->indent()
            ->printfln('$definedFields = [];')
            ->printfln('foreach ($fields as $field => $_) {')
            ->indent()
            ->printfln('$definedFields[$field] = true;')
            ->outdent()
            ->printfln('}')
            ->outdent()
            ->printfln('}')
        ;
        if (isset($compoundChains[$class."\0*"])) {
            $writer
                ->printfln()
                ->printfln('$%s($object, $fields, $definedFields);', $ccSetters[$class."\0*"])
            ;
            unset($compoundChains[$class."\0*"]);
        }
        foreach ($chains as $field => $chain) {
            $writer
                ->printfln()
                ->printfln('if (isset($definedFields[%s])) {', \var_export($field, true))
                ->indent()
                ->printfln('$object->%s = $fields[%s];', $chain[0][1], \var_export($field, true))
                ->outdent()
                ->printfln('}')
            ;
        }
        foreach ($compoundChains as $ccKey => $compoundChain) {
            $subChains = $compoundChain[1];
            self::markParentChains($subChains);
            $writer
                ->printfln()
                ->printfln('if (%s) {', \implode(' || ', \array_map(function (string $field): string {
                    return \sprintf('isset($definedFields[%s])', \var_export($field, true));
                }, \array_keys($compoundChain[1]))))
                ->indent()
                ->printfln('$subObject = $object->%s;', $compoundChain[0][1])
                ->printfln('if (null === $subObject) {')
                ->indent()
                ->printfln('$subObject = $instantiator->instantiate(\\%s::class);', \reset($subChains)[0][0])
                ->printfln('$object->%s = $subObject;', $compoundChain[0][1])
                ->outdent()
                ->printfln('}')
                ->printfln('$%s($subObject, $fields, $definedFields);', $ccSetters[$ccKey])
                ->outdent()
                ->printfln('}')
            ;
        }
        $writer
            ->outdent()
            ->printfln('})->bindTo(null, \\%s::class);', $class)
            ->printfln()
        ;

        $scope[$key] = $id;

        return $id;
    }

    private static function emitBody(StreamWriter $writer, array $chains): void
    {
        $writer
            ->printfln('/** @var bool */')
            ->printfln('private static $initialized = false;')
            ->printfln()
            ->printfln('/** @var \Closure[]|null */')
            ->printfln('private static $fastGetters;')
            ->printfln()
            ->printfln('/** @var \Closure[]|null */')
            ->printfln('private static $fastSetters;')
            ->printfln()
            ->printfln('/** @var \Closure|null */')
            ->printfln('private static $fastBatchGetter;')
            ->printfln()
            ->printfln('/** @var \Closure|null */')
            ->printfln('private static $fastBatchSetter;')
            ->printfln()
            ->printfln('private function __construct() { }')
            ->printfln()
            ->printfln('public static function initialize(): void')
            ->printfln('{')
            ->indent()
            ->printfln('if (self::$initialized) {')
            ->indent()
            ->printfln('return;')
            ->outdent()
            ->printfln('}')
            ->printfln()
        ;
        foreach ($chains as $field => $chain) {
            if (\count($chain) > 1) {
                $writer
                    ->printfln('$instantiator = new Instantiator();')
                    ->printfln()
                ;
                break;
            }
        }
        $scope = [];
        foreach ($chains as $field => $chain) {
            self::emitGetter($writer, $scope, $chain);
            self::emitSetter($writer, $scope, $chain);
        }
        self::emitBatchGetter($writer, $scope, [], $chains);
        self::emitBatchSetter($writer, $scope, [], $chains);
        $writer
            ->printfln('self::$fastGetters = [')
            ->indent()
        ;
        foreach ($chains as $field => $chain) {
            $writer->printfln('%s => $%s,', \var_export($field, true), $scope["get\0".self::serializeChain($chain)]);
        }
        $writer
            ->outdent()
            ->printfln('];')
            ->printfln()
            ->printfln('self::$fastSetters = [')
            ->indent()
        ;
        foreach ($chains as $field => $chain) {
            $writer->printfln('%s => $%s,', \var_export($field, true), $scope["set\0".self::serializeChain($chain)]);
        }
        $writer
            ->outdent()
            ->printfln('];')
            ->printfln()
            ->printfln('self::$fastBatchGetter = $%s;', $scope['bget'])
            ->printfln()
            ->printfln('self::$fastBatchSetter = $%s;', $scope['bset'])
            ->printfln()
            ->printfln('self::$initialized = true;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public static function getFastGetters(): ?array')
            ->printfln('{')
            ->indent()
            ->printfln('return self::$fastGetters;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public static function getFastSetters(): ?array')
            ->printfln('{')
            ->indent()
            ->printfln('return self::$fastSetters;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public static function getFastBatchGetter(): ?\Closure')
            ->printfln('{')
            ->indent()
            ->printfln('return self::$fastBatchGetter;')
            ->outdent()
            ->printfln('}')
            ->printfln()
            ->printfln('public static function getFastBatchSetter(): ?\Closure')
            ->printfln('{')
            ->indent()
            ->printfln('return self::$fastBatchSetter;')
            ->outdent()
            ->printfln('}')
        ;
    }
}
