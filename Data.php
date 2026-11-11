<?php

namespace Omnipost\YouTube;

use Omnipost\Exception\ProviderException;
use Omnisense\Contracts\Http\Data as KernelData;

/**
 * YouTube's and Google's decoded answers read without trusting their
 * shape: the kernel's Data (get() by key or path, has(), toArray()...), the
 * reads that keep PHP's casts of a scalar as they are ("" kept, nothing
 * trimmed) for the values the provider turned into strings and integers
 * with (string) and (int), and those that keep the values it handed on as
 * they came. What PHP refused with a TypeError (a list where a string or an
 * object belongs, a string where a list does) is malformed(): a
 * ProviderException.
 *
 * @internal
 */
final readonly class Data extends KernelData
{
    /** (string) of the value: "" when absent or not a scalar. */
    public function text(string|int $key): string
    {
        return self::cast($this->get($key));
    }

    /** (int) of the value: 0 when absent or not a scalar. */
    public function number(string|int $key): int
    {
        return self::integer($this->get($key));
    }

    /**
     * What a ?string parameter took: null, a scalar as PHP gives it ("" kept, nothing trimmed);
     * a list or an object is malformed().
     */
    public function stringOrNull(string|int $key): ?string
    {
        $value = $this->get($key);
        if (null === $value || \is_scalar($value)) {
            return null === $value ? null : (string) $value;
        }

        throw self::malformed();
    }

    /** An object: null when absent; anything else than an object is malformed(). */
    public function object(string|int $key): ?static
    {
        $value = $this->get($key);
        if (null === $value) {
            return null;
        }

        return \is_array($value) ? static::of($value) : throw self::malformed();
    }

    /**
     * The first object of a list: null when the list is absent or has none at 0; a list that is
     * not one, or a first item that is not an object, is malformed().
     */
    public function first(string|int $key): ?static
    {
        $value = $this->get($key);
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw self::malformed();
        }
        $first = $value[0] ?? null;
        if (null === $first) {
            return null;
        }

        return \is_array($first) ? static::of($first) : throw self::malformed();
    }

    /**
     * The objects of a list (none when absent); a list that is not one, or an item that is not
     * an object, is malformed().
     *
     * @return list<static>
     */
    public function objects(string|int $key): array
    {
        $value = $this->get($key);
        if (null === $value) {
            return [];
        }
        if (!\is_array($value)) {
            throw self::malformed();
        }
        $objects = [];
        foreach ($value as $item) {
            $objects[] = \is_array($item) ? static::of($item) : throw self::malformed();
        }

        return $objects;
    }

    /** (string) of a scalar, "" for anything else. */
    public static function cast(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** (int) of a scalar, 0 for anything else. */
    public static function integer(mixed $value): int
    {
        return \is_scalar($value) ? (int) $value : 0;
    }

    /**
     * A decoded JSON object as it is, its keys as PHP keeps them (an empty array when it is not
     * an array).
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    public static function malformed(): ProviderException
    {
        return new ProviderException(Api::PROVIDER, 'YouTube answered in another shape than its API\'s.');
    }
}
