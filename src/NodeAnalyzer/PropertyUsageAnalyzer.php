<?php

declare(strict_types=1);

namespace Rector\Nette\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\AstResolver;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\FamilyTree\Reflection\FamilyRelationsAnalyzer;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;

final class PropertyUsageAnalyzer
{
    public function __construct(
        private BetterNodeFinder $betterNodeFinder,
        private FamilyRelationsAnalyzer $familyRelationsAnalyzer,
        private NodeNameResolver $nodeNameResolver,
        private AstResolver $astResolver
    ) {
    }

    public function isPropertyFetchedInChildClass(Property $property): bool
    {
        $scope = $property->getAttribute(AttributeKey::SCOPE);
        if (! $scope instanceof Scope) {
            return false;
        }

        $classReflection = $scope->getClassReflection();
        if (! $classReflection instanceof ClassReflection) {
            throw new ShouldNotHappenException();
        }

        if ($classReflection->isClass() && $classReflection->isFinal()) {
            return false;
        }

        $propertyName = $this->nodeNameResolver->getName($property);

        $childrenClassReflections = $this->familyRelationsAnalyzer->getChildrenOfClassReflection($classReflection);

        foreach ($childrenClassReflections as $childClassReflection) {
            $childClass = $this->astResolver->resolveClassFromName($childClassReflection->getName());
            if (! $childClass instanceof Class_) {
                continue;
            }

            $isPropertyFetched = (bool) $this->betterNodeFinder->findFirst(
                $childClass->stmts,
                fn (Node $node): bool => $this->nodeNameResolver->isLocalPropertyFetchNamed($node, $propertyName)
            );

            if ($isPropertyFetched) {
                return true;
            }
        }

        return false;
    }
}
