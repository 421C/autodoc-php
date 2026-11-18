<?php declare(strict_types=1);

namespace AutoDoc\DataTypes\Traits;

use AutoDoc\Analyzer\IntKeyOrder;
use AutoDoc\Config;
use AutoDoc\DataTypes\ObjectType;
use AutoDoc\DataTypes\Type;
use AutoDoc\DataTypes\UnionType;
use AutoDoc\DataTypes\UnknownType;


trait MergesArrayEntries
{
    /**
     * Adds the entries of `$array` the way a spread or `array_merge()` does: string keys overwrite, int keys are renumbered.
     */
    public function mergeArray(Type $array, Config $config): void
    {
        $array = self::getEntriesAddedByMerge($array, $config);

        if ($array === null) {
            return;
        }

        $this->maxItems = null;
        $this->mergeShapeOf($array, $config);

        if (! $array->hasEntriesOutsideShape()) {
            return;
        }

        if ($this->isClosedShape()) {
            $this->intKeyOrder = $this->getIntKeyOrderOfClosedShape($config);
        }

        $this->appendEntriesOutsideShape(
            entries: new self(itemType: $array->itemType, keyType: $array->getRenumberedKeyType($config)),
            config: $config,
        );
    }


    private static function getEntriesAddedByMerge(Type $type, Config $config): ?self
    {
        $type = $type->unwrapType($config);

        if ($type instanceof UnionType) {
            return self::fromUnionOfArrays($type, $config);
        }

        if ($type instanceof self) {
            return match (true) {
                $type->hasNoEntries() => null,
                $type->isUntypedOrKnownEmpty() => self::withUnknownEntries(),
                default => $type,
            };
        }

        if ($type instanceof UnknownType || $type instanceof ObjectType) {
            return self::withUnknownEntries();
        }

        return null;
    }


    private static function withUnknownEntries(): self
    {
        return new self(itemType: new UnknownType);
    }


    private static function fromUnionOfArrays(UnionType $union, Config $config): ?self
    {
        $merged = new self;
        $valueTypesByKey = [];
        $isTuple = true;

        foreach ($union->types as $member) {
            $member = self::getEntriesAddedByMerge($member, $config);

            if ($member === null) {
                continue;
            }

            foreach ($member->shape as $key => $valueType) {
                $valueTypesByKey[$key][] = $valueType;
            }

            $isTuple = $isTuple && ($member->shape === [] || $member->isTuple());
            $merged->mergeEntriesOutsideShape($member, asIntersection: false, config: $config);
        }

        foreach ($valueTypesByKey as $key => $valueTypes) {
            $isInEveryMember = count($valueTypes) === count($union->types);
            $isRequired = $isInEveryMember && array_all($valueTypes, fn (Type $valueType) => $valueType->required);

            $merged->shape[$key] = count($valueTypes) === 1
                ? (clone $valueTypes[0])->setRequired($isRequired)
                : new UnionType($valueTypes)->setRequired($isRequired);
        }

        if ($merged->isUntypedOrKnownEmpty()) {
            return null;
        }

        if (! $isTuple) {
            $merged->intKeyOrder = IntKeyOrder::MaybeSequential;
        }

        return $merged;
    }


    private function mergeShapeOf(self $array, Config $config): void
    {
        if (! $this->mayHoldIntKeysOutsideShape($config)) {
            $this->mergeShape($array->shape, isTuple: $array->isTuple());

            return;
        }

        $entriesAtUnknownIndexes = array_filter($array->shape, fn (int|string $key) => is_int($key), ARRAY_FILTER_USE_KEY);

        $this->mergeShape(array_diff_key($array->shape, $entriesAtUnknownIndexes));

        if ($entriesAtUnknownIndexes !== []) {
            $this->appendEntriesOutsideShape(
                entries: new self(itemType: self::unionUnlessAnyIsUnknown(array_values($entriesAtUnknownIndexes), $config)),
                config: $config,
            );
        }
    }


    private function appendEntriesOutsideShape(self $entries, Config $config): void
    {
        if ($entries->mayHoldIntKeysOutsideShape($config)) {
            $this->addTypeToOptionalListEnd($entries->itemType ?? new UnknownType, $config);
        }

        $this->mergeEntriesOutsideShape($entries, asIntersection: false, config: $config);
    }


    private function addTypeToOptionalListEnd(Type $type, Config $config): void
    {
        for ($key = $this->getFirstKeyOfOptionalListEnd(); isset($this->shape[$key]); $key++) {
            $this->shape[$key] = (clone self::unionUnlessAnyIsUnknown([$this->shape[$key], $type], $config))->setRequired(false);
        }
    }


    private function getFirstKeyOfOptionalListEnd(): int
    {
        $requiredIntKeys = array_keys(array_filter(
            array: $this->shape,
            callback: fn (Type $entry, int|string $key) => is_int($key) && $entry->required,
            mode: ARRAY_FILTER_USE_BOTH,
        ));

        return $requiredIntKeys === [] ? 0 : max($requiredIntKeys) + 1;
    }


    /**
     * @param array<int|string, Type> $shape
     */
    public function mergeShape(array $shape, bool $isTuple = false): void
    {
        // The loop below would turn the tuple [A, B?, C?] into [A, (B|C)?, C?]
        // but in a tuple C exists only when B does.
        if ($isTuple) {
            $this->appendListEntries(array_values($shape));

            return;
        }

        foreach ($shape as $key => $type) {
            if (is_int($key)) {
                $this->appendListEntries([$type]);

            } else if (! $type->required && isset($this->shape[$key])) {
                $existingType = $this->shape[$key];

                $this->shape[$key] = new UnionType([$existingType, $type])->setRequired($existingType->required);

            } else {
                $this->shape[$key] = $type;
            }
        }
    }


    /**
     * @param list<Type> $entries Entries that are missing only from the end.
     */
    private function appendListEntries(array $entries): void
    {
        $firstOptionalKey = $this->getFirstKeyOfOptionalListEnd();

        $optionalEnd = [];

        while (isset($this->shape[$firstOptionalKey + count($optionalEnd)])) {
            $optionalEnd[] = $this->shape[$firstOptionalKey + count($optionalEnd)];
        }

        $candidatesByIndex = array_map(fn (Type $entry) => [clone $entry], $optionalEnd);

        foreach ($entries as $entryIndex => $entry) {
            foreach (range(0, count($optionalEnd)) as $presentOptionalCount) {
                $candidatesByIndex[$presentOptionalCount + $entryIndex][] = (clone $entry)->setRequired(false);
            }
        }

        $requiredEntryCount = count(array_filter($entries, fn (Type $entry) => $entry->required));

        foreach ($candidatesByIndex as $index => $candidates) {
            $type = count($candidates) === 1 ? $candidates[0] : new UnionType($candidates);

            $this->shape[$firstOptionalKey + $index] = $type->setRequired($index < $requiredEntryCount);
        }
    }
}
