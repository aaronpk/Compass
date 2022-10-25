<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;
use Quartz;

class Share extends BaseController
{

  private function _databaseFromToken($token) {
    $share = DB::table('shares')
      ->where('token', $token)
      ->where('expires_at', '>', date('Y-m-d H:i:s'))
      ->first();

    if(!$share) return false;

    $database = DB::table('databases')->where('id', $share->database_id)->first();

    return $database;
  }

  public function view(Request $request, $token) {
    $database = $this->_databaseFromToken($token);

    if(!$database) {
      return view('share-expired');
    }

    return view('share', [
      'database' => $database,
      'share_token' => $token,
    ]);
  }

  public function current_location(Request $request) {
    $database = $this->_databaseFromToken($request->input('token'));

    if(!$database) {
      return response(json_encode(['error' => 'invalid']))->header('Content-Type', 'application/json');
    } 

    $response = [
      'data' => json_decode($database->last_location),
    ];

    return response(json_encode($response))->header('Content-Type', 'application/json');
  }
  
  public function history(Request $request) {
    $database = $this->_databaseFromToken($request->input('token'));
   
    if(!$database) {
      return response(json_encode(['error' => 'invalid']))->header('Content-Type', 'application/json');
    } 
    
    $share = DB::table('shares')
      ->where('token', $request->input('token'))
      ->first();
    $share_date = strtotime($share->created_at);
  
    $locations = [];
    
    $db = new Quartz\DB(env('STORAGE_DIR').$database->name, 'r');
    $results = $db->queryLast(100);
    foreach($results as $id=>$record) {
      if(!is_object($record) || !$record->data)
        continue;
        
      if(!property_exists($record->data->properties, 'horizontal_accuracy')
          || $record->data->properties->horizontal_accuracy >= 5000)
        continue;
         
      // Make sure this is from after the share was created  
      $record_date = $record->date->format('U');
      
      if($record_date < $share_date)
        continue;

      $locations[] = $record->data;
    }
    
    $linestring = array(
      'type' => 'LineString',
      'coordinates' => [],
    );
    foreach($locations as $loc) {
      if(property_exists($loc, 'geometry'))
        $linestring['coordinates'][] = $loc->geometry->coordinates;
      else
        $linestring['coordinates'][] = null;
    }
    
    $response = array(
      'linestring' => $linestring,
    );

    return response(json_encode($response))->header('Content-Type', 'application/json');
  }

}
