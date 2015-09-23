<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;

class Controller extends BaseController
{
  private static function displayURL() {
    return preg_replace('/(^https?:\/\/|\/$)/', '', session('me'));
  }

  public function index(Request $request) {
    if(session('user_id')) {

      $databases = DB::select('SELECT d.*
        FROM `databases` d
        JOIN database_users u ON d.id = u.database_id
        WHERE u.user_id = ?
        ORDER BY name', [session('user_id')]);

      return view('dashboard', [
        'displayURL' => self::displayURL(),
        'databases' => $databases
      ]);
    } else {
      return view('index');
    }
  }

  public function createDatabase(Request $request) {
    if(!session('user_id'))
      return redirect('/');

    if($request->input('name') == '') {
      $request->session()->flash('create-error', 'Enter a name.');
      return redirect('/');
    }

    // Only alphanumeric chars are allowed
    if(preg_replace('/[^a-zA-Z0-9]/', '', $request->input('name')) != $request->input('name')) {
      $request->session()->flash('create-error', 'Only alphanumeric characters are allowed.');
      $request->session()->flash('database-name', preg_replace('/[^a-zA-Z0-9]/','',$request->input('name')));
      return redirect('/');
    }

    // Check for conflicts
    $db = DB::select('SELECT * FROM `databases` WHERE name = ?', [$request->input('name')]);
    if(count($db) == 0) {

      // Create the database records
      $id = DB::table('databases')->insertGetId([
        'name' => $request->input('name'),
        'read_token' => str_random(40),
        'write_token' => str_random(40),
        'created_by' => session('user_id'),
        'created_at' => date('Y-m-d H:i:s')
      ]);
      DB::table('database_users')->insert([
        'database_id' => $id,
        'user_id' => session('user_id'),
        'created_at' => date('Y-m-d H:i:s')
      ]);
      return redirect('/');

    } else {
      $request->session()->flash('create-error', 'That database name is already in use.');
      $request->session()->flash('database-name', $request->input('name'));
      return redirect('/');
    }
  }

  public function map(Request $request, $name) {
    if(!session('user_id'))
      return redirect('/');

    // Verify this user has access to the database
    $check = DB::select('SELECT * 
      FROM `databases` d
      JOIN database_users u ON d.id = u.database_id
      WHERE u.user_id = ? AND d.name = ?', [session('user_id'), $name]);
    if(count($check) == 0) {
      return redirect('/');
    }



  }

  public function settings(Request $request, $name) {
    if(!session('user_id'))
      return redirect('/');

    // Only the person that created the database can modify it
    $db = DB::select('SELECT * 
      FROM `databases`
      WHERE created_by = ? AND name = ?', [session('user_id'), $name]);
    if(count($db) == 0) {
      return redirect('/');
    }

    return view('settings', [
      'displayURL' => self::displayURL(),
      'database' => $db[0]
    ]);
  }

}
