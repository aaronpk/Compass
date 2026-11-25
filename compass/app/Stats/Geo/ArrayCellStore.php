<?php
namespace App\Stats\Geo;

class ArrayCellStore implements CellStore {

  public array $cells = [];

  public function get(string $geohash): array|false {
    return $this->cells[$geohash] ?? false;
  }

  public function put(string $geohash, array $values): void {
    $this->cells[$geohash] = $values;
  }

}
