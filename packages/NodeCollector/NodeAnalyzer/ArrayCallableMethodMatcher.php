<?php

declare(strict_types=1);

namespace Rector\NodeCollector\NodeAnalyzer;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\PhpParser\Node\Value\ValueResolver;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeCollector\ValueObject\ArrayCallable;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;

final class ArrayCallableMethodMatcher
{
    public function __construct(
        private NodeNameResolver $nodeNameResolver,
        private NodeTypeResolver $nodeTypeResolver,
        private ValueResolver $valueResolver,
        private ReflectionProvider $reflectionProvider
    ) {
    }

    /**
     * Matches array like: "[$this, 'methodName']" → ['ClassName', 'methodName']
     */
    public function match(Array_ $array): ?ArrayCallable
    {
        $arrayItems = $array->items;
        if (count($arrayItems) !== 2) {
            return null;
        }

        if ($this->shouldSkipNullItems($array)) {
            return null;
        }

        /** @var ArrayItem[] $items */
        $items = $array->items;
        $secondItemValue = $items[1]->value;

        if (! $secondItemValue instanceof String_) {
            return null;
        }

        if ($this->shouldSkipAssociativeArray($array)) {
            return null;
        }

        // $this, self, static, FQN
        $firstItemValue = $items[0]->value;

        // static ::class reference?
        if ($firstItemValue instanceof ClassConstFetch) {
            $calleeType = $this->resolveClassConstFetchType($firstItemValue);
        } else {
            $calleeType = $this->nodeTypeResolver->resolve($firstItemValue);
        }

        if (! $calleeType instanceof TypeWithClassName) {
            return null;
        }

        if ($this->isCallbackAtFunctionNames($array, ['register_shutdown_function', 'forward_static_call'])) {
            return null;
        }

        $className = $calleeType->getClassName();
        $methodName = $secondItemValue->value;

        if ($methodName === MethodName::CONSTRUCT) {
            return null;
        }

        return new ArrayCallable($firstItemValue, $className, $methodName);
    }

    private function shouldSkipNullItems(Array_ $array): bool
    {
        if ($array->items[0] === null) {
            return true;
        }

        return $array->items[1] === null;
    }

    private function shouldSkipAssociativeArray(Array_ $array): bool
    {
        $values = $this->valueResolver->getValue($array);
        if (! is_array($values)) {
            return false;
        }

        $keys = array_keys($values);
        return $keys !== [0, 1] && $keys !== [1];
    }

    /**
     * @param string[] $functionNames
     */
    private function isCallbackAtFunctionNames(Array_ $array, array $functionNames): bool
    {
        $parentNode = $array->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parentNode instanceof Arg) {
            return false;
        }

        $parentParentNode = $parentNode->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parentParentNode instanceof FuncCall) {
            return false;
        }

        return $this->nodeNameResolver->isNames($parentParentNode, $functionNames);
    }

    private function resolveClassConstFetchType(ClassConstFetch $classConstFetch): MixedType | ObjectType
    {
        $classConstantReference = $this->valueResolver->getValue($classConstFetch);
        if ($classConstantReference === 'static') {
            $classConstantReference = $classConstFetch->getAttribute(AttributeKey::CLASS_NAME);
        }

        // non-class value
        if (! is_string($classConstantReference)) {
            return new MixedType();
        }

        if (! $this->reflectionProvider->hasClass($classConstantReference)) {
            return new MixedType();
        }

        $classReflection = $this->reflectionProvider->getClass($classConstantReference);
        $scope = $classConstFetch->getAttribute(AttributeKey::SCOPE);
        $hasConstruct = $classReflection->hasMethod(MethodName::CONSTRUCT);

        if ($hasConstruct) {
            $methodReflection = $classReflection->getMethod(MethodName::CONSTRUCT, $scope);
            $parametersAcceptor = ParametersAcceptorSelector::selectSingle($methodReflection->getVariants());

            foreach ($parametersAcceptor->getParameters() as $parameterReflection) {
                if ($parameterReflection->getDefaultValue() === null) {
                    return new MixedType();
                }
            }
        }

        return new ObjectType($classConstantReference, null, $classReflection);
    }
}
