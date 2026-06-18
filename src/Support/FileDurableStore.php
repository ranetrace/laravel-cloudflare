<?php

namespace Ranetrace\LaravelCloudflare\Support;

use Illuminate\Support\Facades\Config;
use Ranetrace\LaravelCloudflare\Contracts\DurableStore;

/**
 * Durable last-good store backed by a single JSON file under storage/.
 *
 * Unlike the cache, this survives `cache:clear`/`FLUSHDB` and (when storage/ is
 * shared between releases) deploys, so the last-good list it holds can actually
 * do its job as a fallback when the volatile cache is wiped.
 */
class FileDurableStore implements DurableStore
{
    public function __construct(
        protected string $path,
    ) {}

    public static function fromConfig(): self
    {
        $path = Config::get('laravel-cloudflare.last_good.path');

        if (! is_string($path) || $path === '') {
            $path = storage_path('laravel-cloudflare/last_good.json');
        }

        return new self($path);
    }

    public function getLists(): ?array
    {
        $document = $this->read();

        if (! isset($document['lists']) || ! is_array($document['lists'])) {
            return null;
        }

        $lists = $document['lists'];

        return [
            'v4' => $this->normalizeList($lists['v4'] ?? null),
            'v6' => $this->normalizeList($lists['v6'] ?? null),
            'all' => $this->normalizeList($lists['all'] ?? null),
        ];
    }

    public function getList(string $type): array
    {
        $lists = $this->getLists();

        if ($lists === null) {
            return [];
        }

        return $lists[$this->normalizeType($type)];
    }

    public function putLists(array $ipv4, array $ipv6, array $all): void
    {
        $document = $this->read();

        $document['lists'] = [
            'v4' => array_values($ipv4),
            'v6' => array_values($ipv6),
            'all' => array_values($all),
        ];
        $document['updated_at'] = now()->getTimestamp();

        $this->write($document);
    }

    public function forgetLists(): void
    {
        $document = $this->read();

        unset($document['lists'], $document['updated_at']);

        if ($document === []) {
            $this->delete();

            return;
        }

        $this->write($document);
    }

    public function throttledAt(string $key): ?int
    {
        $document = $this->read();

        $value = $document['throttle'][$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    public function markThrottled(string $key, int $timestamp): void
    {
        $document = $this->read();

        $document['throttle'][$key] = $timestamp;

        $this->write($document);
    }

    public function location(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function write(array $document): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        // Write to a temp file then rename so readers never observe a partial document.
        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return;
        }

        @rename($temporary, $this->path);
    }

    protected function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @return 'v4'|'v6'|'all'
     */
    protected function normalizeType(string $type): string
    {
        return match ($type) {
            'ipv4', 'v4' => 'v4',
            'ipv6', 'v6' => 'v6',
            default => 'all',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, 'is_string'));
    }
}
