<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReservationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('company.api')->group(function () {
    Route::get('/reservaciones/{company}/fecha-actual', [ReservationController::class, 'currentTime']);
    Route::get('/reservaciones/{company}/fecha-lenguaje-humano', [ReservationController::class, 'humanDate']);
    Route::get('/reservaciones/{company}/verificar-disponibilidad', [ReservationController::class, 'checkAvailability']);
    Route::get('/reservaciones/{company}/crear-reserva', [ReservationController::class, 'createReservation']);
});
