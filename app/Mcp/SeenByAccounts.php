<?php

namespace App\Mcp;

/**
 * What several accounts of one connection see, each item once: the same
 * item seen by two accounts (an announcement for every company, an app of
 * both) keeps the first account's copy and lists both companies, in the
 * order the accounts were read (the approving one first). Only the
 * companies of the accounts that saw it are listed, never the ones the
 * item is addressed to: those may be outside the connection.
 *
 * @template TItem
 */
final class SeenByAccounts
{
    /** @var array<string, array{item: TItem, companies: list<string>}> */
    private array $entries = [];

    /**
     * @param  TItem  $item
     */
    public function add(string|int $key, string $company, mixed $item): void
    {
        $key = (string) $key;
        $this->entries[$key] ??= ['item' => $item, 'companies' => []];

        if (! in_array($company, $this->entries[$key]['companies'], true)) {
            $this->entries[$key]['companies'][] = $company;
        }
    }

    /**
     * In the order they were first added.
     *
     * @return list<array{item: TItem, companies: list<string>}>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }
}
