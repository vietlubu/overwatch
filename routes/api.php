<?php

use App\Http\Controllers\Api\NightwatchCommandController;
use App\Http\Controllers\Api\NightwatchExceptionController;
use App\Http\Controllers\Api\NightwatchJobController;
use App\Http\Controllers\Api\NightwatchRequestController;
use App\Http\Controllers\Api\NightwatchScheduledTaskController;
use Illuminate\Support\Facades\Route;

Route::get('/requests', [NightwatchRequestController::class, 'index']);
Route::get('/requests/{executionId}', [NightwatchRequestController::class, 'show']);

Route::get('/exceptions', [NightwatchExceptionController::class, 'index']);
Route::get('/exceptions/{groupHash}', [NightwatchExceptionController::class, 'show']);

Route::get('/jobs', [NightwatchJobController::class, 'index']);
Route::get('/jobs/{jobId}', [NightwatchJobController::class, 'show']);

Route::get('/commands', [NightwatchCommandController::class, 'index']);
Route::get('/commands/{groupHash}', [NightwatchCommandController::class, 'show']);

Route::get('/scheduled-tasks', [NightwatchScheduledTaskController::class, 'index']);
Route::get('/scheduled-tasks/{groupHash}', [NightwatchScheduledTaskController::class, 'show']);
