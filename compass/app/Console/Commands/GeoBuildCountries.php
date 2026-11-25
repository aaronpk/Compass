<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Stats\Geo\CountryIndex;

// Development tool: builds resources/geo/countries.{idx,dat,json} from Natural
// Earth's 1:10m countries file. The built files are committed, so this only
// needs to be run when the source data or the file format changes.
//
//   curl -LO https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_10m_admin_0_countries.geojson
//   php -d memory_limit=1G artisan geo:build-countries ne_10m_admin_0_countries.geojson
class GeoBuildCountries extends Command {

  protected $signature = 'geo:build-countries {source : Path to ne_10m_admin_0_countries.geojson} {--out= : Output directory, defaults to resources/geo}';
  protected $description = 'Build the offline country lookup files from Natural Earth data';

  // [cell index => [country number => signed area of the country in the cell]]
  private array $areas = [];
  // [cell index => [[country number, [u, v, u, v, ...]], ...]]
  private array $pieces = [];

  public function handle() {
    $source = json_decode(file_get_contents($this->argument('source')), true);
    if(!$source || !isset($source['features'])) {
      $this->error('Could not read the source file');
      return 1;
    }
    $out = $this->option('out') ?: resource_path('geo');

    $countries = [];
    $numbers = [];
    foreach($source['features'] as $feature) {
      $p = $feature['properties'];
      // ISO_A2 is -99 for France and Norway; ISO_A2_EH has them. Places with
      // no ISO code at all (Somaliland, Northern Cyprus, ...) keep Natural
      // Earth's own three-letter code.
      $code = preg_match('/^[A-Z]{2}$/', $p['ISO_A2_EH']) ? $p['ISO_A2_EH'] : $p['ADM0_A3'];
      if(!isset($numbers[$code])) {
        $numbers[$code] = count($countries) + 1;
        $countries[] = [
          'num' => $numbers[$code],
          'code' => $code,
          'iso3' => preg_match('/^[A-Z]{3}$/', $p['ISO_A3_EH']) ? $p['ISO_A3_EH'] : $p['ADM0_A3'],
          'name' => $p['NAME_LONG'],
          'continent' => $p['CONTINENT'],
          'sovereign' => $p['SOV_A3'],
        ];
      }
      $number = $numbers[$code];

      $geometry = $feature['geometry'];
      $polygons = $geometry['type'] == 'MultiPolygon' ? $geometry['coordinates'] : [$geometry['coordinates']];
      foreach($polygons as $polygon) {
        foreach($polygon as $r => $ring) {
          $flat = [];
          foreach($ring as $point) {
            $flat[] = ($point[0] + 180) * CountryIndex::CELLS_PER_DEGREE;
            $flat[] = ($point[1] + 90) * CountryIndex::CELLS_PER_DEGREE;
          }
          // The first ring is the outline, the rest are holes
          $this->clip($flat, 0, CountryIndex::COLUMNS, 0, CountryIndex::ROWS, $number, $r == 0 ? 1 : -1);
        }
      }
    }
    if(count($countries) >= CountryIndex::MIXED) {
      $this->error('Too many countries for the index format');
      return 1;
    }

    $idx = '';
    $dat = '';
    $uniform = 0; $mixed = 0;
    for($cell = 0; $cell < CountryIndex::COLUMNS * CountryIndex::ROWS; $cell++) {
      if(!isset($this->areas[$cell])) {
        $idx .= pack('V', 0);
        continue;
      }
      $present = array_filter($this->areas[$cell], fn($area) => abs($area) > 1e-9);
      if(count($present) == 1 && current($present) > 1 - 1e-7) {
        $idx .= pack('V', key($present));
        $uniform++;
        continue;
      }
      $cx = $cell % CountryIndex::COLUMNS;
      $cy = intdiv($cell, CountryIndex::COLUMNS);
      $record = '';
      $rings = 0;
      foreach($this->pieces[$cell] as [$number, $flat]) {
        $packed = [];
        $last = null;
        for($i = 0; $i < count($flat); $i += 2) {
          $x = (int)round(max(0, min(1, $flat[$i] - $cx)) * 65535);
          $y = (int)round(max(0, min(1, $flat[$i + 1] - $cy)) * 65535);
          if($last !== [$x, $y]) {
            $packed[] = $x;
            $packed[] = $y;
            $last = [$x, $y];
          }
        }
        if(count($packed) >= 4 && $packed[0] == $packed[count($packed) - 2] && $packed[1] == $packed[count($packed) - 1])
          array_splice($packed, -2);
        if(count($packed) < 6)
          continue;
        $record .= pack('v2', $number, count($packed) / 2).pack('v*', ...$packed);
        $rings++;
      }
      if($rings == 0) {
        $idx .= pack('V', 0);
        continue;
      }
      $idx .= pack('V', CountryIndex::MIXED + strlen($dat));
      $dat .= pack('v', $rings).$record;
      $mixed++;
    }

    if(!is_dir($out))
      mkdir($out, 0755, true);
    file_put_contents($out.'/countries.idx', $idx);
    file_put_contents($out.'/countries.dat', $dat);
    file_put_contents($out.'/countries.json', json_encode($countries, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");

    $this->info(sprintf('%d countries, %d cells inside one country, %d border cells; idx %d KB, dat %d KB',
      count($countries), $uniform, $mixed, strlen($idx) / 1024, strlen($dat) / 1024));
    return 0;
  }

  // Clips a ring to the grid by cutting it in half over and over until each
  // piece lies in a single cell. Bounds are in whole cells.
  private function clip(array $ring, int $x0, int $x1, int $y0, int $y1, int $number, int $sign): void {
    $count = count($ring);
    if($count < 6)
      return;

    $minU = $maxU = $ring[0];
    $minV = $maxV = $ring[1];
    for($i = 2; $i < $count; $i += 2) {
      if($ring[$i] < $minU) $minU = $ring[$i]; elseif($ring[$i] > $maxU) $maxU = $ring[$i];
      if($ring[$i + 1] < $minV) $minV = $ring[$i + 1]; elseif($ring[$i + 1] > $maxV) $maxV = $ring[$i + 1];
    }
    $x0 = max($x0, (int)floor($minU));
    $x1 = min($x1, (int)ceil($maxU));
    $y0 = max($y0, (int)floor($minV));
    $y1 = min($y1, (int)ceil($maxV));
    if($x1 <= $x0 || $y1 <= $y0)
      return;

    if($x1 - $x0 == 1 && $y1 - $y0 == 1) {
      $area = 0;
      for($i = 0, $j = $count - 2; $i < $count; $j = $i, $i += 2)
        $area += $ring[$j] * $ring[$i + 1] - $ring[$i] * $ring[$j + 1];
      $area = abs($area) / 2;
      if($area < 1e-12)
        return;
      $cell = $y0 * CountryIndex::COLUMNS + $x0;
      $this->areas[$cell][$number] = ($this->areas[$cell][$number] ?? 0) + $sign * $area;
      $this->pieces[$cell][] = [$number, $ring];
      return;
    }

    if($x1 - $x0 >= $y1 - $y0) {
      $mid = intdiv($x0 + $x1, 2);
      $this->clip(self::halfPlane($ring, 0, $mid, true), $x0, $mid, $y0, $y1, $number, $sign);
      $this->clip(self::halfPlane($ring, 0, $mid, false), $mid, $x1, $y0, $y1, $number, $sign);
    } else {
      $mid = intdiv($y0 + $y1, 2);
      $this->clip(self::halfPlane($ring, 1, $mid, true), $x0, $x1, $y0, $mid, $number, $sign);
      $this->clip(self::halfPlane($ring, 1, $mid, false), $x0, $x1, $mid, $y1, $number, $sign);
    }
  }

  // Sutherland-Hodgman against one line: keeps the part of the ring whose
  // coordinate on $axis (0 = u, 1 = v) is below (or above) $limit
  private static function halfPlane(array $ring, int $axis, float $limit, bool $below): array {
    $out = [];
    $count = count($ring);
    $pu = $ring[$count - 2];
    $pv = $ring[$count - 1];
    $pc = $axis ? $pv : $pu;
    $pin = $below ? $pc <= $limit : $pc >= $limit;
    for($i = 0; $i < $count; $i += 2) {
      $u = $ring[$i];
      $v = $ring[$i + 1];
      $c = $axis ? $v : $u;
      $in = $below ? $c <= $limit : $c >= $limit;
      if($in != $pin) {
        $t = ($limit - $pc) / ($c - $pc);
        if($axis) { $out[] = $pu + $t * ($u - $pu); $out[] = $limit; }
        else { $out[] = $limit; $out[] = $pv + $t * ($v - $pv); }
      }
      if($in) {
        $out[] = $u;
        $out[] = $v;
      }
      $pu = $u; $pv = $v; $pc = $c; $pin = $in;
    }
    return $out;
  }

}
