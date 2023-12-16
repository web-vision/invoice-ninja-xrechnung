<?php

use Illuminate\Support\Facades\Route;

Route::group(['middleware' => ['api_db', 'token_auth', 'locale'], 'prefix' => 'api/v1', 'as' => 'api.'], function () {
    Route::post('emails', 'Webvision\NinjaZugferd\Http\Controllers\EmailController@send')->name('email.send')->middleware('user_verified');
});
