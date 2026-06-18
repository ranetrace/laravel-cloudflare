<?php

namespace Ranetrace\LaravelCloudflare\Contracts;

interface DurableStore
{
    /**
     * The last-good lists, or null when nothing has been persisted yet.
     *
     * @return array{v4: array<int, string>, v6: array<int, string>, all: array<int, string>}|null
     */
    public function getLists(): ?array;

    /**
     * The last-good list for a single type ('all'|'ipv4'|'ipv6'), empty when absent.
     *
     * @return array<int, string>
     */
    public function getList(string $type): array;

    /**
     * Persist the last-good lists durably.
     *
     * @param  array<int, string>  $ipv4
     * @param  array<int, string>  $ipv6
     * @param  array<int, string>  $all
     */
    public function putLists(array $ipv4, array $ipv6, array $all): void;

    /**
     * Remove the persisted last-good lists.
     */
    public function forgetLists(): void;

    /**
     * The unix timestamp a throttle key was last marked, or null when never marked.
     */
    public function throttledAt(string $key): ?int;

    /**
     * Mark a throttle key at the given unix timestamp.
     */
    public function markThrottled(string $key, int $timestamp): void;

    /**
     * A human-readable description of where this store persists data (for diagnostics).
     */
    public function location(): string;
}
