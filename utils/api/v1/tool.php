<?php
session_start();
include "../../develop/ui/test/db_conn.php";
if( isset($_SESSION['folder']) && isset($_SESSION['id']) )
{
    $projects_folder = $_SESSION['folder'];
}
else
{
    $projects_folder = dirname(__FILE__) ."/project";
}
    /**
 * ISC License
 *
 * Copyright (c) 2019, Palo Alto Networks Inc.
 * Copyright (c) 2024, Sven Waschkut - pan-os-php@waschkut.net
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

//Todo: swaschkut 20210624
// -
// - user authentication OAuth2 - access to only specific projects
// - create project related to user => response project ID
// - upload config to project folder by using project ID
// - use git module to do all manipulation against one file within a project folder


require_once dirname(__FILE__)."/../../../lib/pan_php_framework.php";
require_once ( dirname(__FILE__)."/../../lib/UTIL.php");

if( !isset( $_GET['shadow-nojson'] ) )
{
    set_exception_handler(function ($e) {
        $code = $e->getCode() ?: 400;
        header("Content-Type: application/json", true, $code);
        print json_encode(["error" => $e->getMessage()]);
        exit;
    });
}



$file_tmp_name = "";
$upload_dir = "";
$PHP_FILE = __FILE__;
if( isset( $_POST['configapi'] ) )
{

}
elseif( !isset( $_GET['in'] ) && isset($_FILES['configInput'])  )
{
    #header('Content-Type: application/json; charset=utf-8');
    #header("Access-Control-Allow-Origin: *");
    #header("Access-Control-Allow-Methods: PUT, GET, POST");

    $response = array();
    $upload_dir = '';
    //$server_url = 'http://localhost:8082/utils/api/v1';

    $file_name = $_FILES['configInput']["name"];
    $file_tmp_name = $_FILES['configInput']["tmp_name"];
    $error = $_FILES['configInput']["error"];

    if($error > 0)
    {
        $message = "Error uploading the file!";
        throw new Exception($message, 404);
    }
    else
    {
        $random = rand(1000,1000000);
        $random_name = $random."-".$file_name;
        $upload_name = $upload_dir.strtolower($random_name);
        $upload_name = preg_replace('/\s+/', '-', $upload_name);

        /*
        if( move_uploaded_file( $file_tmp_name , $upload_name  ) )
        {
            $response = array(
                "status" => "success",
                "error" => false,
                "message" => "File uploaded successfully",
                "url" => $server_url."/".$upload_name,
                "filename" => $upload_name
            );
        }
        else
        {
            $message = "Error uploading the file!";
            throw new Exception($message, 404);
        }
        */
    }
}


// assume JSON, handle requests by verb and path
$verb = $_SERVER['REQUEST_METHOD'];
if( isset( $_SERVER['PATH_INFO'] ) )
    $url_pieces = explode('/', $_SERVER['PATH_INFO']);
else
    $url_pieces = array();


sort(PH::$supportedUTILTypes );

// catch this here, we don't support many routes yet
if( empty( $url_pieces) || ( isset($url_pieces[1]) && !in_array( $url_pieces[1], PH::$supportedUTILTypes ) ) )
{
    $example = "http://localhost:8082/utils/api/v1/tool.php/address?shadow-json";
    $message = 'Unknown endpoint. supported: '.implode( ", ", PH::$supportedUTILTypes ).' Example: '.$example;
    $message = 'Unknown endpoint.';

    #throw new Exception($message, 404);
    $e = new Exception($message, 404);

    $code = $e->getCode() ?: 400;
    header("Content-Type: application/json", true, $code);
    print json_encode(["error" => $e->getMessage(), "endpoint" =>PH::$supportedUTILTypes, "example" => $example ]);
    exit;

}


$argv = array();
$argv[0] = "Standard input code";

if( isset( $_POST['configapi'] ) )
{

}
elseif( !isset($_GET['in']) && isset( $_FILES['configInput'] ) ){
    $argv[] = "in=".$file_tmp_name;
    #$argv[] = "out=".$upload_dir.$random."-new.xml";
    $argv[] = "out=true";
}
elseif( isset($_GET['in']) )
{
    if( !isset($_GET['out']) )
        $argv[] = "out=true";
}
elseif( isset($_GET['help']) || isset($_GET['listfilters']) || isset($_GET['listactions']) || $url_pieces[1] == "key-manager" || $url_pieces[1] == "util_get-action-filter" )
{
}
else{
    #$argv[] = "in=".dirname($PHP_FILE)."/../../../../tests/input/panorama-10.0-merger.xml";
    $message = 'No File available with argument in=';

    throw new Exception($message, 404);

    #$argv[] = "help";
}

if( isset( $_GET['shadow-json'] ) || (!isset( $_GET['shadow-json'] ) && !isset( $_GET['shadow-nojson'] )) )
{
    $argv[] = "shadow-json";
}




switch($verb) {
    case 'GET':
        UTILcaller( $url_pieces, $argv, $argc, $PHP_FILE );

        break;
    // two cases so similar we'll just share code
    case 'POST':
        //introduce uploading XML config file for manipulation
        #print_r($_POST);
        #print_r($HTTP_POST_FILES);
        #print_r($HTTP_POST_VARS);


    case 'PUT':
        // read the JSON
        /*
        $params = json_decode(file_get_contents("php://input"), true);
        if(!$params) {
            throw new Exception("Data missing or invalid");
        }
        if($verb == 'PUT') {
            #$id = $url_pieces[2];
            #$item = $storage->update($id, $params);
            $status = 204;
        } else {
            #$item = $storage->create($params);
            $status = 201;
        }
        */
        #$storage->save();

        // send header, avoid output handler
        #header("Location: " . $item['url'], null,$status);
        #exit;
        #break;

        #throw new Exception("PUT");


        UTILcaller( $url_pieces, $argv, $argc, $PHP_FILE );


        break;
    case 'DELETE':
        $id = $url_pieces[2];
        #$storage->remove($id);
        #$storage->save();
        header("Location: http://localhost:8080/items", null, 204);
        exit;
        break;
    case 'OPTIONS':
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: token, Content-Type');
        header('Content-Length: 0');
        #header('Content-Type: text/plain');
        header('Content-Type: application/xml; charset=utf-8');
        header("HTTP/1.1", null, 204);
        exit;
        break;
    default:
        throw new Exception('Method Not Supported', 405);
}

#header("Content-Type: application/json");
#print json_encode($data);

function UTILcaller( $url_pieces, $argv, $argc, $PHP_FILE )
{
    global $projects_folder;

    if(isset($url_pieces[2]))
    {
        try
        {
            #$data = $storage->getOne($url_pieces[2]);
        } catch (UnexpectedValueException $e) {
            throw new Exception("Resource does not exist", 404);
        }
    }
    else
    {
        if (isset($_GET['help']))
        {
            $argv = array();
            $argv[0] = "Standard input code";
            $argv[] = "shadow-json";
            foreach( $_GET as $key => $get )
            {
                $argv[] = $key;
            }
        }
        elseif (isset($_GET['listactions']))
        {
            $argv = array();
            $argv[0] = "Standard input code";
            $argv[] = "shadow-json";
            $argv[] = "listactions";
        }
        elseif (isset($_GET['listfilters']))
        {
            $argv = array();
            $argv[0] = "Standard input code";
            $argv[] = "shadow-json";
            $argv[] = "listfilters";
        }
        else
        {
            foreach( $_GET as $key => $get )
            {
                if( $key == "in" )
                {
                    //Todo: swaschkut 20250103 - this was the issue that actions was mostly used with actions=display default
                    //why this is/was there????
                    #unset( $argv[1] );
                    if( strpos( $get, "api" ) === false )
                        $get = $projects_folder."/".$get;
                    else
                    {
                        #throw new Exception( "PAN-OS XML API mode is NOT yet supported.", 404);
                    }

                }
                elseif( $key == "out" )
                {
                    $get = $projects_folder."/".$get;
                }

                if( !empty($get) )
                    $value = $key."=".$get;
                else
                    $value = $key;
                $argv[] = $value;
            }
        }

        header("Content-Type: application/json");
        $type = $url_pieces[1];


        $util = PH::callPANOSPHP( $type, $argv, $argc, $PHP_FILE, array(), "", $projects_folder );

        //memory clean up
        unset($util);
    }
}