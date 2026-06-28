<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\SipCredentialController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\InvitationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Campaigns endpoints
Route::get('/campaigns', [CampaignController::class, 'index']);
Route::post('/campaigns', [CampaignController::class, 'store']);

// Agents endpoints
Route::get('/agents', [AgentController::class, 'index']);
Route::post('/agents', [AgentController::class, 'store']);
Route::put('/agents/{id}/status', [AgentController::class, 'updateStatus']);
Route::put('/agents/{id}/queue-eligibility', [AgentController::class, 'updateQueueEligibility']);
Route::put('/agents/{id}/heartbeat', [AgentController::class, 'heartbeat']);
Route::delete('/agents/{id}', [AgentController::class, 'destroy']);

// SIP trunks / settings endpoints
Route::get('/sip', [SipCredentialController::class, 'show']);
Route::put('/sip', [SipCredentialController::class, 'update']);

// Contacts endpoints
Route::get('/contacts', [ContactController::class, 'index']);
Route::post('/contacts', [ContactController::class, 'store']);
Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);

// Companies endpoints
Route::get('/companies', [CompanyController::class, 'index']);
Route::post('/companies', [CompanyController::class, 'store']);

// Invitations endpoints
Route::post('/invitations', [InvitationController::class, 'store']);
Route::post('/invitations/accept', [InvitationController::class, 'accept']);
