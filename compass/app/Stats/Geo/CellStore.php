<?php
namespace App\Stats\Geo;

// Where resolved cells are remembered. get() returns false when the cell
// hasn't been resolved yet, as opposed to a stored "nothing here".
interface CellStore {
  public function get(string $geohash): array|false;
  public function put(string $geohash, array $values): void;
}
