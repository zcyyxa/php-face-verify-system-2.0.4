<?php
use think\facade\Route;

Route::get('/', 'Home/index');
Route::get('index.php', 'Home/index');

Route::rule('api/v4/facehy', 'ApiV4/facehy', 'GET|POST|OPTIONS');
Route::rule('api/v4/face_start', 'ApiV4/faceStart', 'GET|POST|OPTIONS');
Route::rule('api/v4/face_query', 'ApiV4/faceQuery', 'GET|POST|OPTIONS');

Route::get('live', 'Live/index');
Route::get('live.php', 'Live/index');

Route::get('download.php', 'Download/index');
