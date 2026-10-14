<?php
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
define('LARAVEL_START',microtime(true));
$root=is_dir(__DIR__.'/../cvtara_app')?realpath(__DIR__.'/../cvtara_app'):dirname(__DIR__);
if(!is_file($root.'/.env')||(is_dir(__DIR__.'/../cvtara_app')&&!is_file($root.'/storage/installed.lock'))){header('Location: /install.php');exit;}
if(file_exists($maintenance=$root.'/storage/framework/maintenance.php'))require $maintenance;
require $root.'/vendor/autoload.php';
/** @var Application $app */$app=require_once $root.'/bootstrap/app.php';$app->usePublicPath(__DIR__);$app->handleRequest(Request::capture());
