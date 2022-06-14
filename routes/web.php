<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['prefix' => 'secure'], function () {

    //templates
    Route::get('templates', 'TemplatesController@index');
    Route::get('templates/{name}', 'TemplatesController@show');
    Route::post('templates', 'TemplatesController@store');
    Route::put('templates/{name}', 'TemplatesController@update');
    Route::delete('templates', 'TemplatesController@destroy');

    //themes
    Route::get('themes', 'ThemesController@index');

    //projects
    Route::get('projects', 'ProjectsController@index');
    Route::post('projects/{id}/publish', 'PublishProjectController@publish');
    Route::post('projects', 'ProjectsController@store');
    Route::get('projects/{id}', 'ProjectsController@show');
    Route::put('projects/{id}', 'ProjectsController@update');
    Route::put('projects/{project}/toggle-state', 'ProjectsController@ToggleState');
    Route::delete('projects', 'ProjectsController@destroy');
    Route::post('projects/{id}/generate-thumbnail', 'ProjectThumbnailController@store');
    Route::get('projects/{id}/download', 'ProjectDownloadController@download');
    Route::post('projects/{project}/contact', 'ProjectContactFormController@sendMessage');

    //pages
    Route::post('projects/{projectId}/pages', 'ProjectPagesController@store');
    Route::put('projects/{projectId}/pages/{pageName}', 'ProjectPagesController@update');
    Route::delete('projects/{projectId}/pages/{pageName}', 'ProjectPagesController@destroy');

    //elements
    Route::get('elements/custom', 'ElementsController@custom');
});

Route::get('sites/{name}/{page?}', 'UserSiteController@show')->name('user-site-regular');

//FRONT-END ROUTES THAT NEED TO BE PRE-RENDERED
Route::get('/', '\Common\Core\Controllers\HomeController@show')
    ->middleware('prerenderIfCrawler:homepage');

Route::get('{all}', '\Common\Core\Controllers\HomeController@show')->where('all', '.*');
