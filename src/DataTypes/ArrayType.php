<?php declare(strict_types=1);

namespace AutoDoc\DataTypes;

use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Config;
use AutoDoc\DataTypes\Traits\MergesArrayEntries;

/**
 * @phpstan-import-type TypeSchema from Type
 * @phpstan-type PathKey int|string|Type|null
 */
class ArrayType extends Type
{
    use MergesArrayEntries;

    public function __construct(
        public ?Type $itemType = null,
        public ?Type $keyType = null,

        /**
         * @var array<int|string, Type>
         */
        public array $shape = [],

        /**
         * For collection classes that are resolved as arrays.
         *
         * @var ?class-string<object>
         */
        public ?string $className = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public private(set) IntKeyOrder $intKeyOrder = IntKeyOrder::Sequential,
    ) {}


    public static function withoutEntries(): self
    {
        return new self(maxItems: 0);
    }


    public function isTuple(): bool
    {
        [$listEntries, $otherEntries] = self::splitListEntries($this->shape);

        if (! $this->hasListForm($listEntries, $otherEntries)) {
            return false;
        }

        return $this->intKeyOrder === IntKeyOrder::Sequential
            || ($otherEntries === [] && ! self::hasOptionalEntryBeforeLast($listEntries));
    }


    public function mayEncodeAsList(Config $config): bool
    {
        if (! $this->isClosedShape()) {
            return $this->intKeyOrder !== IntKeyOrder::NonSequential && ! $this->mayHaveStringKeys($config);
        }

        [$listEntries, $otherEntries] = self::splitListEntries($this->shape);

        return $this->hasListForm($listEntries, $otherEntries);
    }


    public function mayEncodeAsObject(Config $config): bool
    {
        if (! $this->isClosedShape()) {
            return $this->intKeyOrder !== IntKeyOrder::Sequential || ! $this->mayEncodeAsList($config);
        }

        return ! $this->isTuple();
    }


    private function isClosedShape(): bool
    {
        return $this->shape !== [] && ! $this->hasEntriesOutsideShape();
    }


    private function mayHaveStringKeys(Config $config): bool
    {
        return array_any(array_keys($this->shape), fn (int|string $key) => is_string($key))
            || self::includesStringKeys($this->keyType, $config);
    }


    /**
     * @return ?list<Type>
     */
    public function getTupleEntries(): ?array
    {
        [$listEntries, $otherEntries] = self::splitListEntries($this->shape);

        if (! $this->hasListForm($listEntries, $otherEntries)) {
            return null;
        }

        $minItems = self::tupleMinItems($listEntries);
        $tupleEntries = [];

        foreach ($listEntries as $index => $entry) {
            $tupleEntries[] = $index < $minItems && ! $entry->required
                ? (clone $entry)->setRequired(true)
                : $entry;
        }

        return $tupleEntries;
    }


    /**
     * @return ?list<Type>
     */
    public function getDocumentedTupleEntries(Config $config): ?array
    {
        $tupleEntries = $this->getTupleEntries();

        if ($tupleEntries === null || ! $this->hasEntriesOutsideShape()) {
            return $tupleEntries;
        }

        return array_all($tupleEntries, fn (Type $entry) => $this->otherEntriesAllowTypeOf($entry, $config))
            ? null
            : $tupleEntries;
    }


    private function otherEntriesAllowTypeOf(Type $entry, Config $config): bool
    {
        $typeOfOtherEntries = ($this->itemType ?? new UnknownType)->unwrapType($config);

        if ($typeOfOtherEntries instanceof UnknownType) {
            return $entry->unwrapType($config) instanceof UnknownType;
        }

        return $entry->isSubTypeOf($typeOfOtherEntries, $config);
    }


    /**
     * @param array<int|string, Type> $shape
     * @return array{list<Type>, list<Type>}
     */
    private static function splitListEntries(array $shape): array
    {
        $listEntries = [];
        $otherEntries = [];

        foreach ($shape as $key => $entry) {
            if ($key === count($listEntries)) {
                $listEntries[] = $entry;

            } else {
                $otherEntries[] = $entry;
            }
        }

        return [$listEntries, $otherEntries];
    }


    /**
     * @param list<Type> $listEntries
     * @param list<Type> $otherEntries
     */
    private function hasListForm(array $listEntries, array $otherEntries): bool
    {
        if ($listEntries === []) {
            return false;
        }

        if ($this->hasEntriesOutsideShape()) {
            return $this->intKeyOrder !== IntKeyOrder::NonSequential && $otherEntries === [];
        }

        return match ($this->intKeyOrder) {
            IntKeyOrder::NonSequential => false,
            IntKeyOrder::Sequential => $otherEntries === [] && self::optionalEntriesTrail($listEntries),
            IntKeyOrder::MaybeSequential => ! array_any($otherEntries, fn (Type $entry) => $entry->required),
        };
    }


    /**
     * @param list<Type> $entries
     */
    private static function tupleMinItems(array $entries): int
    {
        $requiredIndexes = array_keys(array_filter($entries, fn (Type $entry) => $entry->required));

        return $requiredIndexes === [] ? 0 : max($requiredIndexes) + 1;
    }


    /**
     * @param list<Type> $entries
     */
    private static function optionalEntriesTrail(array $entries): bool
    {
        return array_all(array_slice($entries, 0, self::tupleMinItems($entries)), fn (Type $entry) => $entry->required);
    }


    /**
     * @param list<Type> $entries
     */
    private static function hasOptionalEntryBeforeLast(array $entries): bool
    {
        return ! array_all(array_slice($entries, 0, -1), fn (Type $entry) => $entry->required);
    }


    public function toSchema(Config $config): array
    {
        if ($this->shape === []) {
            $this->keyType = $this->keyType?->unwrapType($config);
            $this->itemType = $this->itemType?->unwrapType($config);
        }

        $mayEncodeAsList = $this->mayEncodeAsList($config);

        if ($mayEncodeAsList && $this->mayEncodeAsObject($config)) {
            return $this->withOwnMetadata([
                'anyOf' => [
                    $this->listSchema($config),
                    $this->objectSchema($config),
                ],
            ]);
        }

        return $this->withOwnMetadata($mayEncodeAsList ? $this->listSchema($config) : $this->objectSchema($config));
    }


    /**
     * @return TypeSchema
     */
    private function listSchema(Config $config): array
    {
        $documentedTupleEntries = $this->getDocumentedTupleEntries($config);

        if ($documentedTupleEntries !== null) {
            $hasEntriesOutsideShape = $this->hasEntriesOutsideShape();
            $requiredEntryCount = count(array_filter($documentedTupleEntries, fn (Type $entry) => $entry->required));

            return array_filter([
                'type' => 'array',
                'prefixItems' => array_map(fn (Type $entry) => $entry->toSchema($config), $documentedTupleEntries),
                'items' => $this->schemaOfEntriesOutsideShape($config),
                'minItems' => $hasEntriesOutsideShape ? max($requiredEntryCount, $this->minItems ?? 0) : $requiredEntryCount,
                'maxItems' => $hasEntriesOutsideShape ? null : count($documentedTupleEntries),
            ]);
        }

        return array_filter([
            'type' => 'array',
            'items' => $this->getTypeOfEveryEntry($config)->toSchema($config),
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
        ]);
    }


    /**
     * @return TypeSchema
     */
    private function objectSchema(Config $config): array
    {
        if ($this->shape !== []) {
            return array_filter([
                'type' => 'object',
                'properties' => self::propertySchemas($this->shape, $config),
                'required' => self::requiredPropertyNames($this->shape),
                'additionalProperties' => $this->hasEntriesOutsideShape() ? $this->schemaOfEntriesOutsideShape($config) ?? true : null,
            ]);
        }

        return array_filter([
            'type' => 'object',
            'additionalProperties' => ($this->itemType ?? new UnknownType)->toSchema($config),
            'minProperties' => $this->minItems,
            'maxProperties' => $this->maxItems,
        ]);
    }


    /**
     * @return ?TypeSchema
     */
    private function schemaOfEntriesOutsideShape(Config $config): ?array
    {
        $itemType = $this->itemType?->unwrapType($config);

        if ($itemType === null || $itemType instanceof UnknownType) {
            return null;
        }

        return $itemType->toSchema($config);
    }


    /**
     * Whether PHP may store a key of this type as a string, as it does `null` and non-numeric strings.
     */
    public static function mayBeStoredAsStringKey(Type $keyType, Config $config): bool
    {
        return self::includesStringKeys(self::toStoredKeyType($keyType, $config), $config);
    }


    public static function mayBeStoredAsIntKey(Type $keyType, Config $config): bool
    {
        $storedKeyType = self::toStoredKeyType($keyType, $config);
        $storedKeyTypes = $storedKeyType instanceof UnionType ? $storedKeyType->types : [$storedKeyType];

        return array_any($storedKeyTypes, fn (Type $type) => $type instanceof IntegerType);
    }


    private static function includesStringKeys(?Type $keyType, Config $config): bool
    {
        $keyType = $keyType?->unwrapType($config);
        $keyTypes = $keyType instanceof UnionType ? $keyType->types : array_filter([$keyType]);

        return array_any($keyTypes, fn (Type $keyType) => ! $keyType instanceof IntegerType && ! $keyType instanceof NumberType);
    }


    private static function toStoredKeyType(Type $keyType, Config $config): Type
    {
        $keyType = $keyType->unwrapType($config);
        $keyTypes = $keyType instanceof UnionType ? array_values($keyType->types) : [$keyType];

        return new UnionType(array_map(
            callback: fn (Type $type): Type => self::isStoredAsStringKey($type) ? new StringType : new IntegerType,
            array: $keyTypes,
        ))->unwrapType($config);
    }


    private static function isStoredAsStringKey(Type $type): bool
    {
        if ($type instanceof NullType) {
            return true;
        }

        if (! $type instanceof StringType) {
            return false;
        }

        $possibleValues = $type->getPossibleValues();

        return $possibleValues === null || ! array_all($possibleValues, fn (string $value) => (string) (int) $value === $value);
    }


    /**
     * The key type after spreading or `array_merge()`: string keys stay, int keys get new values.
     */
    public function getRenumberedKeyType(Config $config): ?Type
    {
        $keyType = $this->keyType?->unwrapType($config);

        if ($keyType === null) {
            return null;
        }

        $keyTypes = $keyType instanceof UnionType ? array_values($keyType->types) : [$keyType];

        return new UnionType(array_map(
            callback: fn (Type $type) => $type instanceof IntegerType ? new IntegerType : $type,
            array: $keyTypes,
        ))->unwrapType($config);
    }


    public function convertShapeToTypePair(Config $config, bool $removePossibleItemValues = false): self
    {
        if ($this->shape) {
            $hasEntriesOutsideShape = $this->hasEntriesOutsideShape();

            if (! $hasEntriesOutsideShape) {
                $this->intKeyOrder = $this->getIntKeyOrderOfClosedShape($config);
            }

            $keyTypes = [];
            $itemTypes = [];

            $showValuesForScalarTypes = $config->data['openapi']['show_values_for_scalar_types'] ?? false;

            foreach ($this->shape as $key => $value) {
                if (is_int($key)) {
                    $keyTypes[] = new IntegerType($showValuesForScalarTypes ? $key : null);

                } else {
                    $keyTypes[] = new StringType($showValuesForScalarTypes ? $key : null);
                }

                $itemTypes[] = $value->unwrapType($config);
            }

            if ($hasEntriesOutsideShape) {
                $keyTypes[] = $this->keyType ?? new IntegerType;
                $itemTypes[] = $this->itemType ?? new UnknownType;
            }

            $this->keyType = new UnionType($keyTypes)->unwrapType($config);
            $this->itemType = new UnionType($itemTypes)->unwrapType($config);
            $this->shape = [];
        }

        if ($removePossibleItemValues) {
            $removeItemValues = function (Type $type) use (&$removeItemValues): Type {
                if ($type instanceof IntegerType
                    || $type instanceof FloatType
                    || $type instanceof NumberType
                    || $type instanceof StringType
                ) {
                    $type = clone $type;
                    $type->value = null;
                }

                if ($type instanceof UnionType || $type instanceof IntersectionType) {
                    $type = clone $type;
                    $type->types = array_map($removeItemValues, $type->types);
                }

                return $type;
            };

            if ($this->itemType) {
                $this->itemType = $removeItemValues($this->itemType);
            }

            if ($this->keyType) {
                $this->keyType = $removeItemValues($this->keyType);
            }
        }

        return $this;
    }


    public function getTypeOfEveryEntry(Config $config): Type
    {
        return (clone $this)->convertShapeToTypePair($config)->itemType ?? new UnknownType;
    }


    private function getIntKeyOrderOfClosedShape(Config $config): IntKeyOrder
    {
        $hasIntKeys = array_any(array_keys($this->shape), fn (int|string $key) => is_int($key));

        return match (true) {
            ! $hasIntKeys, ! $this->mayEncodeAsObject($config) => IntKeyOrder::Sequential,
            $this->mayEncodeAsList($config) => IntKeyOrder::MaybeSequential,
            default => IntKeyOrder::NonSequential,
        };
    }


    public function addItemToArray(int|string|Type|null $key, Type $itemType, Config $config): self
    {
        $this->maxItems = null;

        if ($key instanceof Type) {
            if (! self::mayBeStoredAsStringKey($key, $config)) {
                return $this->addIntKeyedItem($itemType, $config);
            }

            $this->convertShapeToTypePair($config);
            $this->keyType = new UnionType(array_values(array_filter([
                $this->hasEntriesOutsideShape() ? $this->keyType ?? new IntegerType : null,
                self::toStoredKeyType($key, $config),
            ])))->unwrapType($config);
            $this->itemType = $this->itemType === null ? $itemType : new UnionType([$this->itemType, $itemType])->unwrapType($config);

            return $this;
        }

        if ($key === null || is_int($key)) {
            if ($key !== null && $this->shape !== [] && array_any(array_keys($this->shape), fn ($shapeKey) => is_string($shapeKey))) {
                $this->shape[$key] = $itemType;

                return $this;
            }

            return $this->addIntKeyedItem($itemType, $config);
        }

        if ($this->shape || $this->itemType === null || $itemType->required) {
            $typeOfEntryOutsideShape = $itemType->required ? null : $this->getTypeOfEntryOutsideShapeAt($key, $config);

            $this->shape[$key] = $typeOfEntryOutsideShape === null
                ? $itemType
                : self::unionUnlessAnyIsUnknown([$itemType, clone $typeOfEntryOutsideShape], $config)->setRequired(false);

        } else {
            $this->keyType = new UnionType(array_values(array_filter([$this->keyType, new StringType])))->unwrapType($config);
            $this->itemType = new UnionType([$this->itemType, $itemType])->unwrapType($config);
        }

        return $this;
    }


    private function addIntKeyedItem(Type $itemType, Config $config): self
    {
        $this->convertShapeToTypePair($config);
        $this->keyType = new UnionType(array_values(array_filter([$this->keyType, new IntegerType])))->unwrapType($config);
        $this->itemType = $this->itemType === null ? $itemType : new UnionType([$this->itemType, $itemType])->unwrapType($config);

        return $this;
    }


    public function removeItemFromArray(int|string $key, bool $isCertain = true): self
    {
        if (! isset($this->shape[$key])) {
            return $this->isClosedShape() ? $this : $this->markEntriesOutsideShapeOptional();
        }

        if ($isCertain) {
            unset($this->shape[$key]);

        } else {
            $this->shape[$key] = (clone $this->shape[$key])->setRequired(false);
        }

        if ($this->hasEntriesOutsideShape()) {
            return $this->markEntriesOutsideShapeOptional();
        }

        if ($this->shape === []) {
            $this->intKeyOrder = IntKeyOrder::Sequential;
            $this->maxItems = 0;

            return $this;
        }

        $this->intKeyOrder = $this->intKeyOrder->union(IntKeyOrder::NonSequential);

        return $this;
    }


    public function markItemsOptional(): self
    {
        foreach (array_keys($this->shape) as $shapeKey) {
            $this->removeItemFromArray($shapeKey, isCertain: false);
        }

        return $this->markEntriesOutsideShapeOptional();
    }


    public function markEntriesOutsideShapeOptional(): self
    {
        $this->minItems = null;

        if ($this->hasEntriesOutsideShape()) {
            $this->intKeyOrder = $this->intKeyOrder->union(IntKeyOrder::NonSequential);
        }

        return $this;
    }


    /**
     * Reorders the entries and keeps their keys, as `asort()` does, so int keys may end up out of order.
     */
    public function reorderItems(Config $config): self
    {
        if (! $this->mayEncodeAsList($config)) {
            return $this;
        }

        $this->convertShapeToTypePair($config);

        if ($this->hasEntriesOutsideShape()) {
            $this->intKeyOrder = $this->intKeyOrder->union(IntKeyOrder::NonSequential);
        }

        return $this;
    }


    public function placeIntKeysOutOfOrder(): self
    {
        $this->intKeyOrder = $this->intKeyOrder->union(IntKeyOrder::NonSequential);

        return $this;
    }


    /**
     * Renumbers the int keys in order and keeps the string keys.
     */
    public function renumberKeys(Config $config): self
    {
        if ($this->shape !== []) {
            $renumberedArray = new self;
            $renumberedArray->mergeShape($this->shape, isTuple: $this->isTuple());
            $this->shape = $renumberedArray->shape;

        } else {
            $this->keyType = $this->getRenumberedKeyType($config);
        }

        $this->intKeyOrder = IntKeyOrder::Sequential;

        return $this;
    }


    public function toList(Config $config): self
    {
        $this->convertShapeToTypePair($config);
        $this->keyType = null;
        $this->intKeyOrder = IntKeyOrder::Sequential;

        return $this;
    }


    public function flipKeysAndItems(Config $config): self
    {
        $this->convertShapeToTypePair($config);
        [$this->keyType, $this->itemType] = [$this->itemType, $this->keyType ?? new IntegerType];
        $this->intKeyOrder = IntKeyOrder::MaybeSequential;

        return $this;
    }


    public function tryMergeIntKeyOrder(self $other, bool $asIntersection, Config $config): bool
    {
        if (! $asIntersection
            && ($this->mayEncodeAsList($config) !== $other->mayEncodeAsList($config) || ! $other->keepsListEntriesInKeyOrderOf($this))
        ) {
            return false;
        }

        $this->intKeyOrder = $this->intKeyOrder->merge($other->intKeyOrder, $asIntersection);

        return true;
    }


    private function keepsListEntriesInKeyOrderOf(self $target): bool
    {
        $inTargetKeyOrder = array_replace(array_intersect_key($target->shape, $this->shape), $this->shape);

        return self::splitListEntries($inTargetKeyOrder)[0] === self::splitListEntries($this->shape)[0];
    }


    public function hasEntriesOutsideShape(): bool
    {
        return $this->itemType !== null || $this->keyType !== null;
    }


    /**
     * @internal
     */
    public function isUntypedOrKnownEmpty(): bool
    {
        return $this->shape === [] && ! $this->hasEntriesOutsideShape();
    }


    private function hasNoEntries(): bool
    {
        return $this->isUntypedOrKnownEmpty() && $this->maxItems === 0;
    }


    /**
     * The type of an entry under `$key` among the entries outside the shape, or null when none of them can be under that key.
     */
    public function getTypeOfEntryOutsideShapeAt(int|string $key, Config $config): ?Type
    {
        $mayHoldKey = is_int($key)
            ? $this->mayHoldIntKeysOutsideShape($config)
            : $this->hasEntriesOutsideShape() && self::mayBeStoredAsStringKey($this->keyType ?? new IntegerType, $config);

        return $mayHoldKey ? $this->itemType ?? new UnknownType : null;
    }


    /**
     * Whether a required entry is an intersection that no value satisfies, so no such array exists.
     */
    public function hasRequiredEntryThatCanNotExist(Config $config): bool
    {
        return array_any(
            array: $this->shape,
            callback: fn (Type $entry) => $entry->required
                && ($entry instanceof NeverType || ($entry instanceof IntersectionType && $entry->unwrapType($config) instanceof NeverType)),
        );
    }


    protected function fitsIntoArray(self $superArray, Config $config, bool $asRefinement): bool
    {
        if (! $this->isOfClassOf($superArray)) {
            return false;
        }

        if ($asRefinement && $this->shape !== []) {
            return $this->listsEveryKeyOf($superArray, $config);
        }

        if ($superArray->shape !== []) {
            return ! $asRefinement && $this->fitsIntoShapeOf($superArray, $config);
        }

        $thisAsTypePair = (clone $this)->convertShapeToTypePair($config);

        if ($superArray->keyType !== null
            && ! ($thisAsTypePair->keyType ?? new IntegerType)->fitsInto($superArray->keyType, $config, $asRefinement)
        ) {
            return false;
        }

        if ($superArray->itemType === null) {
            return true;
        }

        return $thisAsTypePair->itemType !== null && $thisAsTypePair->itemType->fitsInto($superArray->itemType, $config, $asRefinement);
    }


    private function isOfClassOf(self $superArray): bool
    {
        if ($superArray->className === null || $this->className === $superArray->className) {
            return true;
        }

        if ($this->className === null || ! class_exists($this->className) || ! class_exists($superArray->className)) {
            return false;
        }

        return is_a($this->className, $superArray->className, true);
    }


    private function listsEveryKeyOf(self $declaredArray, Config $config): bool
    {
        return array_all(
            array: $declaredArray->shape,
            callback: fn (Type $declaredEntry, int|string $key) => isset($this->shape[$key])
                && self::entryFitsInto($this->shape[$key], $declaredEntry, $config, asRefinement: true),
        );
    }


    private function fitsIntoShapeOf(self $superArray, Config $config): bool
    {
        if ($this->isUntypedOrKnownEmpty()) {
            return $this->hasNoEntries() && array_all($superArray->shape, fn (Type $superEntry) => ! $superEntry->required);
        }

        foreach ($superArray->shape as $key => $superEntry) {
            if (! $this->hasEntryFitting($key, $superEntry, $config)) {
                return false;
            }
        }

        if (! $superArray->hasEntriesOutsideShape()) {
            return true;
        }

        foreach (array_diff_key($this->shape, $superArray->shape) as $key => $entry) {
            $superTypeOutsideShape = $superArray->getTypeOfEntryOutsideShapeAt($key, $config);

            if ($superTypeOutsideShape === null || ! $entry->isSubTypeOf($superTypeOutsideShape, $config)) {
                return false;
            }
        }

        if (! $this->hasEntriesOutsideShape()) {
            return true;
        }

        return ($this->keyType ?? new IntegerType)->isSubTypeOf($superArray->keyType ?? new IntegerType, $config)
            && ($this->itemType ?? new UnknownType)->isSubTypeOf($superArray->itemType ?? new UnknownType, $config);
    }


    private function hasEntryFitting(int|string $key, Type $superEntry, Config $config): bool
    {
        $entry = $this->shape[$key] ?? null;

        if ($entry !== null) {
            return self::entryFitsInto($entry, $superEntry, $config, asRefinement: false);
        }

        if ($superEntry->required) {
            return false;
        }

        $typeOutsideShape = $this->getTypeOfEntryOutsideShapeAt($key, $config);

        return $typeOutsideShape === null || $typeOutsideShape->isSubTypeOf($superEntry, $config);
    }


    private static function entryFitsInto(Type $entry, Type $superEntry, Config $config, bool $asRefinement): bool
    {
        return $entry->fitsInto($superEntry, $config, $asRefinement) && ($entry->required || ! $superEntry->required);
    }


    /**
     * @internal
     */
    public function mergeEntriesOutsideShape(self $other, bool $asIntersection, Config $config): void
    {
        if (! $other->hasEntriesOutsideShape()) {
            return;
        }

        if (! $this->hasEntriesOutsideShape()) {
            $this->itemType = $other->itemType;
            $this->keyType = $other->keyType;

            return;
        }

        $neitherDeclaresAKeyType = $this->keyType === null && $other->keyType === null;
        $itemTypes = [$this->itemType ?? new UnknownType, $other->itemType ?? new UnknownType];
        $keyTypes = [$this->keyType ?? new IntegerType, $other->keyType ?? new IntegerType];

        $itemType = $asIntersection
            ? new IntersectionType($itemTypes)->unwrapType($config)
            : self::unionUnlessAnyIsUnknown($itemTypes, $config);
        $keyType = $asIntersection
            ? new IntersectionType($keyTypes)->unwrapType($config)
            : new UnionType($keyTypes)->unwrapType($config);

        if ($itemType instanceof NeverType || $keyType instanceof NeverType) {
            $this->itemType = null;
            $this->keyType = null;

            return;
        }

        $this->itemType = $itemType;
        $this->keyType = $neitherDeclaresAKeyType ? null : $keyType;
    }


    /**
     * @internal
     *
     * @param list<Type> $types
     */
    public static function unionUnlessAnyIsUnknown(array $types, Config $config): Type
    {
        $types = array_map(fn (Type $type) => $type->unwrapType($config), $types);

        if (array_any($types, fn (Type $type) => $type instanceof UnknownType)) {
            return new UnknownType;
        }

        return new UnionType($types)->unwrapType($config);
    }


    private function mayHoldIntKeysOutsideShape(Config $config): bool
    {
        if (! $this->hasEntriesOutsideShape()) {
            return false;
        }

        return $this->keyType === null || ! self::toStoredKeyType($this->keyType, $config) instanceof StringType;
    }
}
