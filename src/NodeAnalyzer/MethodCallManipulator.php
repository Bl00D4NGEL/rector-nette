<?php

declare(strict_types=1);

namespace Rector\Nette\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Defluent\NodeAnalyzer\FluentChainMethodCallNodeAnalyzer;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;

final class MethodCallManipulator
{
    public function __construct(
        private BetterNodeFinder $betterNodeFinder,
        private NodeNameResolver $nodeNameResolver,
        private FluentChainMethodCallNodeAnalyzer $fluentChainMethodCallNodeAnalyzer
    ) {
    }

    /**
     * @return string[]
     */
    public function findMethodCallNamesOnVariable(Variable $variable): array
    {
        $methodCallsOnVariable = $this->findMethodCallsOnVariable($variable);

        $methodCallNamesOnVariable = [];
        foreach ($methodCallsOnVariable as $methodCallOnVariable) {
            $methodName = $this->nodeNameResolver->getName($methodCallOnVariable->name);
            if ($methodName === null) {
                continue;
            }

            $methodCallNamesOnVariable[] = $methodName;
        }

        return array_unique($methodCallNamesOnVariable);
    }

    /**
     * @return MethodCall[]
     */
    public function findMethodCallsIncludingChain(MethodCall $methodCall): array
    {
        $chainMethodCalls = [];

        // 1. collect method chain call
        $currentMethodCallee = $methodCall->var;
        while ($currentMethodCallee instanceof MethodCall) {
            $chainMethodCalls[] = $currentMethodCallee;
            $currentMethodCallee = $currentMethodCallee->var;
        }

        // 2. collect on-same-variable calls
        $onVariableMethodCalls = [];
        if ($currentMethodCallee instanceof Variable) {
            $onVariableMethodCalls = $this->findMethodCallsOnVariable($currentMethodCallee);
        }

        $methodCalls = array_merge($chainMethodCalls, $onVariableMethodCalls);

        return $this->uniquateObjects($methodCalls);
    }

    public function findAssignToVariable(Variable $variable): ?Assign
    {
        $parentNode = $variable->getAttribute(AttributeKey::PARENT_NODE);
        if (! $parentNode instanceof Node) {
            return null;
        }

        $variableName = $this->nodeNameResolver->getName($variable);
        if ($variableName === null) {
            return null;
        }

        do {
            $assign = $this->findAssignToVariableName($parentNode, $variableName);
            if ($assign instanceof Assign) {
                return $assign;
            }

            $parentNode = $this->resolvePreviousNodeInSameScope($parentNode);
        } while ($parentNode instanceof Node && ! $parentNode instanceof FunctionLike);

        return null;
    }

    /**
     * @return MethodCall[]
     */
    public function findMethodCallsOnVariable(Variable $variable): array
    {
        // get scope node, e.g. parent function call, method call or anonymous function
        $classMethod = $this->betterNodeFinder->findParentType($variable, ClassMethod::class);
        if (! $classMethod instanceof ClassMethod) {
            return [];
        }

        $variableName = $this->nodeNameResolver->getName($variable);
        if ($variableName === null) {
            return [];
        }

        /** @var MethodCall[] $methodCalls */
        $methodCalls = $this->betterNodeFinder->findInstanceOf($classMethod, MethodCall::class);

        return array_filter($methodCalls, function (MethodCall $methodCall) use ($variableName): bool {
            // cover fluent interfaces too
            $callerNode = $this->fluentChainMethodCallNodeAnalyzer->resolveRootExpr($methodCall);
            if (! $callerNode instanceof Variable) {
                return false;
            }

            return $this->nodeNameResolver->isName($callerNode, $variableName);
        });
    }

    /**
     * @see https://stackoverflow.com/a/4507991/1348344
     * @param object[] $objects
     * @return object[]
     *
     * @template T
     * @phpstan-param array<T>|T[] $objects
     * @phpstan-return array<T>|T[]
     */
    private function uniquateObjects(array $objects): array
    {
        $uniqueObjects = [];
        foreach ($objects as $object) {
            if (in_array($object, $uniqueObjects, true)) {
                continue;
            }

            $uniqueObjects[] = $object;
        }

        // re-index
        return array_values($uniqueObjects);
    }

    private function findAssignToVariableName(Node $node, string $variableName): ?Node
    {
        /** @var Assign[] $assigns */
        $assigns = $this->betterNodeFinder->findInstanceOf($node, Assign::class);

        foreach ($assigns as $assign) {
            if (! $assign->var instanceof Variable) {
                continue;
            }

            if (! $this->nodeNameResolver->isName($assign->var, $variableName)) {
                continue;
            }

            return $assign;
        }

        return null;
    }

    private function resolvePreviousNodeInSameScope(Node $parentNode): ?Node
    {
        $previousParentNode = $parentNode;
        $parentNode = $parentNode->getAttribute(AttributeKey::PARENT_NODE);

        if (! $parentNode instanceof FunctionLike) {
            // is about to leave → try previous expression
            $previousStatement = $previousParentNode->getAttribute(AttributeKey::PREVIOUS_STATEMENT);
            if ($previousStatement instanceof Expression) {
                return $previousStatement->expr;
            }
        }

        return $parentNode;
    }
}
