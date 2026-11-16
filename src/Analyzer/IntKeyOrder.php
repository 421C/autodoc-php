<?php declare(strict_types=1);

namespace AutoDoc\Analyzer;

enum IntKeyOrder
{
    case Sequential;
    case MaybeSequential;
    case NonSequential;


    public function union(self $other): self
    {
        return $this === $other ? $this : self::MaybeSequential;
    }


    public function intersect(self $other): self
    {
        return match (true) {
            $this === $other, $other === self::MaybeSequential => $this,
            $this === self::MaybeSequential => $other,
            default => self::NonSequential,
        };
    }


    public function merge(self $other, bool $asIntersection): self
    {
        return $asIntersection ? $this->intersect($other) : $this->union($other);
    }
}
