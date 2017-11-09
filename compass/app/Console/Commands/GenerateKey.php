<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;

class GenerateKey extends Command {

  protected $signature = 'key:generate';
  protected $description = 'Generate a random key for APP_KEY';

  public function handle() {
    $key = bin2hex(random_bytes(16));
    $this->line('Below is a random string you can use for APP_KEY in the .env file');
    $this->info($key);
  }

}
