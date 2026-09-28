<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ToolServerController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 * The hub's REST API. Bodies and responses follow the contract's JSON Schemas
 * (resources/contract); reference: docs/api.md.
 */
Route::prefix('v1')->group(function () {
    Route::get('meta', [MetaController::class, 'show']);
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::get('invitations/{token}', [InvitationController::class, 'preview'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);
        Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show']);
        Route::patch('workspaces/{workspace}', [WorkspaceController::class, 'update']);
        Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy']);

        Route::get('workspaces/{workspace}/members', [MemberController::class, 'index']);
        Route::patch('workspaces/{workspace}/members/{user}', [MemberController::class, 'update']);
        Route::delete('workspaces/{workspace}/members/{user}', [MemberController::class, 'destroy']);
        Route::post('workspaces/{workspace}/leave', [MemberController::class, 'leave']);

        Route::get('workspaces/{workspace}/invitations', [InvitationController::class, 'index']);
        Route::post('workspaces/{workspace}/invitations', [InvitationController::class, 'store']);
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereUlid('invitation');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);

        Route::get('workspaces/{workspace}/agents', [AgentController::class, 'index']);
        Route::post('workspaces/{workspace}/agents', [AgentController::class, 'store']);
        Route::get('agents/{agent}', [AgentController::class, 'show']);
        Route::patch('agents/{agent}', [AgentController::class, 'update']);
        Route::delete('agents/{agent}', [AgentController::class, 'destroy']);

        Route::get('workspaces/{workspace}/tool-servers', [ToolServerController::class, 'index']);
        Route::post('workspaces/{workspace}/tool-servers', [ToolServerController::class, 'store']);
        Route::patch('tool-servers/{toolServer}', [ToolServerController::class, 'update']);
        Route::delete('tool-servers/{toolServer}', [ToolServerController::class, 'destroy']);

        Route::get('workspaces/{workspace}/conversations', [ConversationController::class, 'index']);
        Route::post('workspaces/{workspace}/conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::post('conversations/{conversation}/cancel', [ConversationController::class, 'cancel']);
        Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::get('conversations/{conversation}/usage', [UsageController::class, 'conversation']);

        Route::post('conversations/{conversation}/runs', [RunController::class, 'store']);
        Route::post('runs/{run}/events', [RunController::class, 'events']);
        Route::post('runs/{run}/approvals', [RunController::class, 'approval']);
        Route::post('runs/{run}/heartbeat', [RunController::class, 'heartbeat']);
        Route::post('runs/{run}/finish', [RunController::class, 'finish']);

        Route::get('workspaces/{workspace}/search', SearchController::class);
        Route::post('workspaces/{workspace}/attachments', [AttachmentController::class, 'store']);
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show']);

        Route::get('workspaces/{workspace}/usage/summary', [UsageController::class, 'summary']);
        Route::get('workspaces/{workspace}/usage/timeseries', [UsageController::class, 'timeseries']);
        Route::get('workspaces/{workspace}/usage/records', [UsageController::class, 'records']);
    });
});
