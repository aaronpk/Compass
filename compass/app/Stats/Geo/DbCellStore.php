<?php
namespace App\Stats\Geo;

use Illuminate\Support\Facades\DB;

// Cells remembered in a database table, with a copy in memory for this process
class DbCellStore implements CellStore {

  private string $table;
  private array $memory = [];

  public function __construct(string $table) {
    $this->table = $table;
  }

  public function get(string $geohash): array|false {
    if(array_key_exists($geohash, $this->memory))
      return $this->memory[$geohash];
    $row = DB::table($this->table)->where('geohash', $geohash)->first();
    if(!$row)
      return false;
    $values = (array)$row;
    unset($values['geohash']);
    $this->remember($geohash, $values);
    return $values;
  }

  public function put(string $geohash, array $values): void {
    DB::table($this->table)->upsert([array_merge(['geohash' => $geohash], $values)], ['geohash'], array_keys($values));
    $this->remember($geohash, $values);
  }

  private function remember(string $geohash, array $values): void {
    // A long backfill touches many cells; don't let the copy grow without limit
    if(count($this->memory) > 20000)
      $this->memory = [];
    $this->memory[$geohash] = $values;
  }

}
