<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\SipCredentialController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\DialerSessionController;
use App\Http\Controllers\CallEventController;
use App\Http\Controllers\AgentCallController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Campaigns — each owns its own lead list and its own calling team
Route::get('/campaigns', [CampaignController::class, 'index']);
Route::post('/campaigns', [CampaignController::class, 'store']);
Route::get('/campaigns/{id}', [CampaignController::class, 'show']);
Route::put('/campaigns/{id}', [CampaignController::class, 'update']);
Route::delete('/campaigns/{id}', [CampaignController::class, 'destroy']);
Route::get('/campaigns/{id}/leads', [CampaignController::class, 'leads']);
Route::post('/campaigns/{id}/leads', [CampaignController::class, 'importLeads']);
Route::put('/campaigns/{id}/agents', [CampaignController::class, 'setAgents']);

// Agents endpoints
Route::get('/agents', [AgentController::class, 'index']);
Route::post('/agents', [AgentController::class, 'store']);
Route::put('/agents/{id}/status', [AgentController::class, 'updateStatus']);
Route::put('/agents/{id}/queue-eligibility', [AgentController::class, 'updateQueueEligibility']);
Route::put('/agents/{id}/heartbeat', [AgentController::class, 'heartbeat']);
Route::put('/agents/{id}/active-campaign', [AgentController::class, 'updateActiveCampaign']);
Route::delete('/agents/{id}', [AgentController::class, 'destroy']);

// SIP trunks / settings endpoints
Route::get('/sip', [SipCredentialController::class, 'show']);
Route::put('/sip', [SipCredentialController::class, 'update']);

// Contacts endpoints
Route::get('/contacts', [ContactController::class, 'index']);
Route::post('/contacts', [ContactController::class, 'store']);
Route::put('/contacts/{id}', [ContactController::class, 'update']);
Route::delete('/contacts/{id}', [ContactController::class, 'destroy']);

// Companies endpoints
Route::get('/companies', [CompanyController::class, 'index']);
Route::post('/companies', [CompanyController::class, 'store']);

// Invitations endpoints
Route::post('/invitations', [InvitationController::class, 'store']);
Route::post('/invitations/accept', [InvitationController::class, 'accept']);

// Bulk dialer — session control (frontend)
Route::get('/dialer/session', [DialerSessionController::class, 'show']);
Route::post('/dialer/session', [DialerSessionController::class, 'start']);
Route::post('/dialer/session/drain', [DialerSessionController::class, 'drain']);
Route::post('/dialer/session/stop', [DialerSessionController::class, 'stop']);
Route::get('/dialer/attempts', [DialerSessionController::class, 'attempts']);
Route::get('/dialer/attempts/{id}/recording', [DialerSessionController::class, 'recording']);
Route::get('/dialer/softphone/{userId}', [DialerSessionController::class, 'softphone']);
Route::put('/dialer/attempts/{id}/disposition', [CallEventController::class, 'disposition']);

// Bulk dialer — Asterisk call events forwarded by mkb-dialer-core
Route::post('/dialer/events', [CallEventController::class, 'store']);

// Single-agent call control
Route::post('/dialer/next-call', [AgentCallController::class, 'nextCall']);
Route::post('/dialer/three-way', [AgentCallController::class, 'threeWay']);
Route::post('/dialer/three-way/drop', [AgentCallController::class, 'threeWayDrop']);
Route::post('/dialer/transfer', [AgentCallController::class, 'transfer']);
Route::get('/dialer/slots', [AgentCallController::class, 'getSlots']);
Route::put('/dialer/slots', [AgentCallController::class, 'putSlots']);
