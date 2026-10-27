<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingDocumentController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\Api\Staff\BranchController;
use App\Http\Controllers\Api\Staff\BusinessSettingController;
use App\Http\Controllers\Api\Staff\DeliveryZoneController;
use App\Http\Controllers\Api\Staff\PaymentOptionController;
use App\Http\Controllers\Api\Staff\RentalAgreementController;
use App\Http\Controllers\Api\Staff\VehicleController as StaffVehicleController;
use App\Http\Controllers\Api\Staff\VehiclePhotoController;
use App\Http\Controllers\Api\Staff\VehicleUnitController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/vehicles/home-feed', [VehicleController::class, 'homeFeed']);
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->whereNumber('vehicle');

    Route::get('/booking-locations', [ReferenceDataController::class, 'locations']);
    Route::get('/payment-methods', [ReferenceDataController::class, 'paymentMethods']);
    Route::get('/rental-agreement', [ReferenceDataController::class, 'agreement']);

    // The signed-in renter's own bookings; another renter's booking ID answers 404.
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings/quote', [BookingController::class, 'quote'])->middleware('throttle:booking-quotes');
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:booking-create');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking');
    Route::post('/bookings/{booking}/requirements', [BookingDocumentController::class, 'uploadRequirement'])->whereNumber('booking')->middleware('throttle:booking-uploads');
    Route::post('/bookings/{booking}/payment-proof', [BookingDocumentController::class, 'uploadPaymentProof'])->whereNumber('booking')->middleware('throttle:booking-uploads');
    // Private files, served only to the booking's renter and to staff.
    Route::get('/bookings/{booking}/requirements/{type}/file', [BookingDocumentController::class, 'downloadRequirement'])->whereNumber('booking')->name('bookings.requirements.file');
    Route::get('/bookings/{booking}/payment-proof/file', [BookingDocumentController::class, 'downloadPaymentProof'])->whereNumber('booking')->name('bookings.payment-proof.file');

    // Staff and admin endpoints. Vehicles are authorized by VehiclePolicy; reference data by the
    // "manage-reference-data" (staff + admin) and "manage-business-config" (admin) gates; bookings
    // by "manage-bookings" (staff + admin).
    Route::prefix('staff')->name('staff.')->group(function () {
        Route::get('/bookings', [StaffBookingController::class, 'index'])->name('bookings.index');
        Route::get('/bookings/{booking}', [StaffBookingController::class, 'show'])->name('bookings.show');
        Route::patch('/bookings/{booking}/requirements/{type}', [StaffBookingController::class, 'reviewRequirement'])->name('bookings.requirements.review');
        Route::patch('/bookings/{booking}/payment', [StaffBookingController::class, 'reviewPayment'])->name('bookings.payment.review');
        Route::post('/bookings/{booking}/confirm', [StaffBookingController::class, 'confirm'])->name('bookings.confirm');
        Route::post('/bookings/{booking}/activate', [StaffBookingController::class, 'activate'])->name('bookings.activate');
        Route::post('/bookings/{booking}/cancel', [StaffBookingController::class, 'cancel'])->name('bookings.cancel');

        Route::apiResource('vehicles', StaffVehicleController::class);
        Route::post('/vehicles/{vehicle}/units', [VehicleUnitController::class, 'store'])->name('vehicles.units.store');
        Route::patch('/units/{unit}', [VehicleUnitController::class, 'update'])->name('units.update');
        Route::post('/vehicles/{vehicle}/photos', [VehiclePhotoController::class, 'store'])->name('vehicles.photos.store');
        Route::delete('/photos/{photo}', [VehiclePhotoController::class, 'destroy'])->name('photos.destroy');

        Route::apiResource('branches', BranchController::class)->only(['index', 'store', 'update']);
        Route::apiResource('delivery-zones', DeliveryZoneController::class)->only(['index', 'store', 'update'])->parameters(['delivery-zones' => 'zone']);
        Route::apiResource('payment-options', PaymentOptionController::class)->only(['index', 'store', 'update'])->parameters(['payment-options' => 'option']);
        Route::apiResource('rental-agreements', RentalAgreementController::class)->only(['index', 'store', 'update'])->parameters(['rental-agreements' => 'agreement']);
        Route::post('/rental-agreements/{agreement}/publish', [RentalAgreementController::class, 'publish'])->name('rental-agreements.publish');
        Route::get('/settings', [BusinessSettingController::class, 'show'])->name('settings.show');
        Route::patch('/settings', [BusinessSettingController::class, 'update'])->name('settings.update');
    });
});
