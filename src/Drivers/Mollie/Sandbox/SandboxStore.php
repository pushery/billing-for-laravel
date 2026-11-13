<?php

declare(strict_types=1);

namespace Pushery\Billing\Drivers\Mollie\Sandbox;

use Illuminate\Contracts\Cache\Repository;

/**
 * Where the sandbox keeps what the driver sent it: each customer, payment, mandate and refund as the object Mollie
 * would answer with, the lists that group them, the counters that number them, and the answer each idempotency key
 * got.
 *
 * Everything is kept without expiry, because a mandate has to last until the cycle that charges it, a month or a
 * year after the first payment. The store is a cache store of the application itself, so nothing of it leaves the
 * install.
 */
final readonly class SandboxStore
{
    private const string PREFIX = 'billing:mollie-sandbox:';

    public function __construct(private Repository $cache) {}

    /**
     * The object stored under the id, or null for an id the sandbox never issued.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $object = $this->cache->get(self::PREFIX.'object:'.$id);

        return is_array($object) ? $this->keyed($object) : null;
    }

    /** @param  array<string, mixed>  $object */
    public function put(string $id, array $object): void
    {
        $this->cache->forever(self::PREFIX.'object:'.$id, $object);
    }

    public function forget(string $id): void
    {
        $this->cache->forget(self::PREFIX.'object:'.$id);
    }

    /**
     * The next id under the prefix, counted from 1 for each prefix: `tr_sbx000001`, then `tr_sbx000002`.
     *
     * The counter is read as a number whatever the store hands back, because a store such as Redis answers an
     * increment it keeps as text.
     */
    public function nextId(string $prefix): string
    {
        $key = self::PREFIX.'counter:'.$prefix;

        $this->cache->add($key, 0);
        $next = $this->cache->increment($key);

        return $prefix.'_sbx'.str_pad((string) (is_numeric($next) ? (int) $next : 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The ids in the list, in the order they joined it.
     *
     * @return list<string>
     */
    public function members(string $list): array
    {
        $members = $this->cache->get(self::PREFIX.'list:'.$list);

        return is_array($members) ? array_values(array_filter($members, is_string(...))) : [];
    }

    public function join(string $list, string $id): void
    {
        $this->cache->forever(self::PREFIX.'list:'.$list, [...$this->members($list), $id]);
    }

    /**
     * The answer a request under this idempotency key got, or null for a key not seen yet.
     *
     * @return array{status: int, body: string}|null
     */
    public function answered(string $key): ?array
    {
        $answer = $this->cache->get(self::PREFIX.'idempotency:'.hash('sha256', $key));

        if (! is_array($answer) || ! is_numeric($answer['status'] ?? null) || ! is_string($answer['body'] ?? null)) {
            return null;
        }

        return ['status' => (int) $answer['status'], 'body' => $answer['body']];
    }

    public function remember(string $key, int $status, string $body): void
    {
        $this->cache->forever(self::PREFIX.'idempotency:'.hash('sha256', $key), ['status' => $status, 'body' => $body]);
    }

    /**
     * @param  array<mixed>  $object
     * @return array<string, mixed>
     */
    private function keyed(array $object): array
    {
        $keyed = [];

        foreach ($object as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
