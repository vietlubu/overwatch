<?php

use App\Http\Controllers\Api\NightwatchCommandController;
use App\Http\Controllers\Api\NightwatchCacheController;
use App\Http\Controllers\Api\NightwatchDashboardController;
use App\Http\Controllers\Api\NightwatchExceptionController;
use App\Http\Controllers\Api\NightwatchIssueController;
use App\Http\Controllers\Api\NightwatchJobController;
use App\Http\Controllers\Api\NightwatchLogController;
use App\Http\Controllers\Api\NightwatchMailController;
use App\Http\Controllers\Api\NightwatchNotificationController;
use App\Http\Controllers\Api\NightwatchOutgoingRequestController;
use App\Http\Controllers\Api\NightwatchProjectController;
use App\Http\Controllers\Api\NightwatchQueryController;
use App\Http\Controllers\Api\NightwatchRequestController;
use App\Http\Controllers\Api\NightwatchScheduledTaskController;
use App\Http\Controllers\Api\NightwatchUserController;
use Illuminate\Support\Facades\Route;

Route::get('/projects', [NightwatchProjectController::class, 'index']);
Route::get('/dashboard', [NightwatchDashboardController::class, 'index']);
Route::get('/issues', [NightwatchIssueController::class, 'index']);
Route::get('/issues/{issueKey}', [NightwatchIssueController::class, 'show']);
Route::patch('/issues/{issueKey}/resolve', [NightwatchIssueController::class, 'resolve']);

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

Route::get('/queries', [NightwatchQueryController::class, 'index']);
Route::get('/queries/{groupHash}', [NightwatchQueryController::class, 'show']);

Route::get('/notifications', [NightwatchNotificationController::class, 'index']);
Route::get('/notifications/{groupHash}', [NightwatchNotificationController::class, 'show']);

Route::get('/mail', [NightwatchMailController::class, 'index']);
Route::get('/mail/{groupHash}', [NightwatchMailController::class, 'show']);

Route::get('/cache', [NightwatchCacheController::class, 'index']);
Route::get('/cache/{groupHash}', [NightwatchCacheController::class, 'show']);

Route::get('/outgoing-requests', [NightwatchOutgoingRequestController::class, 'index']);
Route::get('/outgoing-requests/{groupHash}', [NightwatchOutgoingRequestController::class, 'show']);

Route::get('/users', [NightwatchUserController::class, 'index']);
Route::get('/users/{externalUserId}', [NightwatchUserController::class, 'show']);

Route::get('/logs', [NightwatchLogController::class, 'index']);
Route::get('/logs/{logId}', [NightwatchLogController::class, 'show']);
