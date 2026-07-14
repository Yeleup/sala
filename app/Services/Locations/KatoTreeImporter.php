<?php

namespace App\Services\Locations;

use App\Models\Location;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Imports the KATO tree from the box-drawing text export (one node per
 * line, indentation in 4-character groups). Idempotent: nodes are matched
 * by parent + name, so re-running only adds what is missing.
 */
class KatoTreeImporter
{
    /**
     * @return array{created: int, total: int}
     */
    public function import(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        throw_if($lines === false, new RuntimeException("Cannot read the KATO tree file: {$path}"));

        $created = 0;

        DB::transaction(function () use ($lines, &$created): void {
            /** @var array<int, Location> $stack */
            $stack = [];

            foreach ($lines as $line) {
                $parsed = $this->parseLine(rtrim($line));

                if ($parsed === null) {
                    continue; // The «Казахстан» root line and anything unparseable.
                }

                [$depth, $name] = $parsed;
                $parent = $depth > 0 ? ($stack[$depth - 1] ?? null) : null;

                throw_if(
                    $depth > 0 && $parent === null,
                    new RuntimeException("Orphan node «{$name}» — the tree file is malformed."),
                );

                $node = Location::query()
                    ->where('parent_id', $parent?->id)
                    ->where('name', $name)
                    ->first();

                if ($node === null) {
                    // Explicit fields + quiet saves: seeders may run inside
                    // Model::withoutEvents, so the model hooks are not
                    // relied upon here.
                    $node = new Location([
                        'name' => $name,
                        'depth' => $depth,
                        'search_name' => LocationName::searchKey($name),
                    ]);
                    $node->parent_id = $parent?->id;
                    $node->saveQuietly();
                    $node->updateQuietly(['path' => ($parent?->path ?? '/').$node->id.'/']);
                    $created++;
                }

                $stack[$depth] = $node;
                $stack = array_slice($stack, 0, $depth + 1, preserve_keys: true);
            }
        });

        return ['created' => $created, 'total' => Location::count()];
    }

    /**
     * @return array{0: int, 1: string}|null Depth and node name.
     */
    private function parseLine(string $line): ?array
    {
        if (preg_match('/^((?:│   |    )*)(?:├── |└── )(.+)$/u', $line, $matches) !== 1) {
            return null;
        }

        return [(int) (mb_strlen($matches[1]) / 4), trim($matches[2])];
    }
}
