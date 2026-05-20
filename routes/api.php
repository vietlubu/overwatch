<?php

use App\Http\Controllers\Api\NightwatchExceptionController;
use App\Http\Controllers\Api\NightwatchJobController;
use App\Http\Controllers\Api\NightwatchRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/requests', [NightwatchRequestController::class, 'index']);
Route::get('/requests/{executionId}', [NightwatchRequestController::class, 'show']);

Route::get('/exceptions', [NightwatchExceptionController::class, 'index']);
Route::get('/exceptions/{groupHash}', [NightwatchExceptionController::class, 'show']);

Route::get('/jobs', [NightwatchJobController::class, 'index']);
Route::get('/jobs/{jobId}', [NightwatchJobController::class, 'show']);
