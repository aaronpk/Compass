<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;

class Controller extends BaseController
{
  public function index(Request $request) {
    if(session('user_id')) {

      $databases = DB::select('SELECT d.*
        FROM `databases` d
        JOIN database_users u ON d.id = u.database_id
        WHERE u.user_id = ?', [session('user_id')]);

      return view('dashboard', [
        'displayURL' => preg_replace('/(^https?:\/\/|\/$)/', '', session('me')),
        'databases' => $databases
      ]);
    } else {
      return view('index');
    }
  }

  public function createDatabase(Request $request) {
    if(session('user_id')) {

      if($request->input('name') == '') {
        return redirect('/');
      }

      // Only alphanumeric chars are allowed
      if(preg_replace('/[^a-zA-Z0-9]/', '', $request->input('name')) != $request->input('name')) {
        $request->session()->flash('error', 'Only alphanumeric characters are allowed.');
        $request->session()->flash('database-name', preg_replace('/[^a-zA-Z0-9]/','',$request->input('name')));
        return redirect('/');
      }

      // Check for conflicts
      $db = DB::select('SELECT * FROM `databases` WHERE name = ?', [$request->input('name')]);
      if(count($db) == 0) {

        // Create the database records
        $id = DB::table('databases')->insertGetId([
          'name' => $request->input('name'),
          'created_by' => session('user_id'),
          'created_at' => date('Y-m-d H:i:s')
        ]);
        DB::table('database_users')->insert([
          'database_id' => $id,
          'user_id' => session('user_id'),
          'created_at' => date('Y-m-d H:i:s')
        ]);

      } else {
        $request->session()->flash('error', 'That database name is already in use.');
        $request->session()->flash('database-name', $request->input('name'));
        return redirect('/');
      }

    } else {
      return redirect('/');
    }
  }
}
