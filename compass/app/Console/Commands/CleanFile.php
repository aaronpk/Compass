<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Laravel\Lumen\Routing\DispatchesJobs;
use Log;
use DateTime, DateTimeZone;

class Cleanfile extends Command {

  protected $signature = 'clean:file {in} {out}';
  protected $description = 'Discard duplicate out of order data from a file';

  public function handle() {
    $in = $this->argument('in');
    $out = $this->argument('out');

    $fp = @fopen($in, 'r');
    $outf = @fopen($out, 'w');
    if($fp && $outf) {

      $last = false;
      while(($line = fgets($fp)) !== false) {
        $cur = new DateTime(substr($line, 0, 26), new DateTimeZone('UTC'));

        if(!$last) {
          $last = new DateTime(substr($line, 0, 26), new DateTimeZone('UTC'));
          fwrite($outf, $line);
        } else {
          if((double)$cur->format('U.u') > (double)$last->format('U.u')) {
            fwrite($outf, $line);
            $last = new DateTime(substr($line, 0, 26), new DateTimeZone('UTC'));
          } else {
            Log::info("Discarding line");
          }
        }
      }
      fclose($fp);
      fclose($outf);

    } else {
      Log::error("Could not find input file");
    }
  }

}
