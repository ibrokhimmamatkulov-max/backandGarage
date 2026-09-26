<?php

use App\Http\Controllers\Api\ApplicationStatusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CarBodyTypeController;
use App\Http\Controllers\Api\CarBrandController;
use App\Http\Controllers\Api\CarCategoryController;
use App\Http\Controllers\Api\CarClassController;
use App\Http\Controllers\Api\CarConditionController;
use App\Http\Controllers\Api\CarModelController;
use App\Http\Controllers\Api\CarOptionController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\LandingController;
use App\Http\Controllers\Api\ColorCarController;
use App\Http\Controllers\Api\GearboxController;
use App\Http\Controllers\Api\PerformerTransportPhotoController;
use App\Http\Controllers\Api\RentalApplicationController;
use App\Http\Controllers\Api\RentalTariffController;
use App\Http\Controllers\Api\RoleController;
// Гараж 2.0 — кабинет арендодателя и модерация
use App\Http\Controllers\Api\Moderation\ListingModerationController;
use App\Http\Controllers\Api\Moderation\DocumentReviewController;
use App\Http\Controllers\Api\Moderation\OwnerManagementController;
use App\Http\Controllers\Api\Owner\ApplicationController as OwnerApplicationController;
use App\Http\Controllers\Api\Owner\AuthController as OwnerAuthController;
use App\Http\Controllers\Api\Owner\AvailabilityController as OwnerAvailabilityController;
use App\Http\Controllers\Api\Owner\ListingController as OwnerListingController;
use App\Http\Controllers\Api\Owner\ListingDocumentController as OwnerListingDocumentController;
use App\Http\Controllers\Api\Owner\ListingPhotoController as OwnerListingPhotoController;
use App\Http\Controllers\Api\Owner\ProfileController as OwnerProfileController;
use App\Http\Controllers\Api\Owner\ReferenceController as OwnerReferenceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});


Route::middleware('auth:api')->group(function (){

    Route::get('rental-tariffs', [RentalTariffController::class, 'index']);
    Route::post('rental-tariffs', [RentalTariffController::class, 'store']);
    Route::get('rental-tariffs/{id}', [RentalTariffController::class, 'show']);
    Route::put('rental-tariffs/{id}', [RentalTariffController::class, 'update']);
    Route::delete('rental-tariffs/{id}', [RentalTariffController::class, 'destroy']);

    Route::get('cities', [CityController::class, 'index']);
    Route::post('cities', [CityController::class, 'store']);
    Route::get('cities/{id}', [CityController::class, 'show']);
    Route::put('cities/{id}', [CityController::class, 'update']);
    Route::patch('cities/{id}', [CityController::class, 'update']);
    Route::delete('cities/{id}', [CityController::class, 'destroy']);
    Route::get('application-statuses', [ApplicationStatusController::class, 'index']);
    Route::post('application-statuses', [ApplicationStatusController::class, 'store']);
    Route::get('application-statuses/{id}', [ApplicationStatusController::class, 'show']);
    Route::put('application-statuses/{id}', [ApplicationStatusController::class, 'update']);
    Route::delete('application-statuses/{id}', [ApplicationStatusController::class, 'destroy']);

    Route::get('gearboxes', [GearboxController::class, 'index']);
    Route::post('gearboxes', [GearboxController::class, 'store']);
    Route::get('gearboxes/{id}', [GearboxController::class, 'show']);
    Route::put('gearboxes/{id}', [GearboxController::class, 'update']);
    Route::delete('gearboxes/{id}', [GearboxController::class, 'destroy']);

    Route::get('car-photos/{car_id}', [PerformerTransportPhotoController::class, 'index']);
    Route::post('car-photos', [PerformerTransportPhotoController::class, 'store']);
    Route::delete('car-photos/{id}', [PerformerTransportPhotoController::class, 'destroy']);

    Route::get('rental-applications', [RentalApplicationController::class, 'index']);
    Route::get('rental-applications/summary', [RentalApplicationController::class, 'summary']);
    Route::post('rental-applications', [RentalApplicationController::class, 'store']);
    Route::get('rental-applications/{id}', [RentalApplicationController::class, 'show']);
    Route::put('rental-applications/{id}', [RentalApplicationController::class, 'update']);
    Route::delete('rental-applications/{id}', [RentalApplicationController::class, 'destroy']);

    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::patch('/roles/{id}', [RoleController::class, 'update']);
    Route::delete('/roles/{id}', [RoleController::class, 'destroy']);

    Route::get('/car-settings/categories', [CarCategoryController::class, 'index']);
    Route::post('/car-settings/categories', [CarCategoryController::class, 'store']);
    Route::get('/car-settings/categories/{category_car_id}/edit', [CarCategoryController::class, 'show']);
    Route::patch('/car-settings/categories/{category_car_id}', [CarCategoryController::class, 'update']);

    Route::get('/car-settings/model-cars', [CarModelController::class, 'index']);
    Route::post('/car-settings/model-cars', [CarModelController::class, 'store']);
    Route::post('/car-settings/model-cars/data', [CarModelController::class, 'data']);
    Route::get('/car-settings/model-cars/{car_model_id}/edit', [CarModelController::class, 'edit']);
    Route::patch('/car-settings/model-cars/{car_model_id}', [CarModelController::class, 'update']);

    Route::get('/car-settings/brands', [CarBrandController::class, 'index']);
    Route::get('/car-settings/brands/{brand_id}/edit', [CarBrandController::class, 'edit']);
    Route::post('/car-settings/brands', [CarBrandController::class, 'store']);
    Route::patch('/car-settings/brands/{brand_id}', [CarBrandController::class, 'update']);

    Route::get('/car-settings/classes', [CarClassController::class, 'index']);
    Route::post('/car-settings/classes', [CarClassController::class, 'store']);
    Route::get('/car-settings/classes/{class_car_id}/edit', [CarClassController::class, 'edit']);
    Route::patch('/car-settings/classes/{class_car_id}', [CarClassController::class, 'update']);

    Route::get('/car-settings/body-types', [CarBodyTypeController::class, 'index']);
    Route::post('/car-settings/body-types', [CarBodyTypeController::class, 'store']);
    Route::get('/car-settings/body-types/{body_type_id}/edit', [CarBodyTypeController::class, 'edit']);
    Route::patch('/car-settings/body-types/{body_type_id}', [CarBodyTypeController::class, 'update']);

    Route::get('/car-settings/car-colors', [ColorCarController::class, 'index']);
    Route::post('/car-settings/car-colors', [ColorCarController::class, 'store']);
    Route::get('/car-settings/car-colors/{color_id}/edit', [ColorCarController::class, 'edit']);
    Route::patch('/car-settings/car-colors/{color_id}', [ColorCarController::class, 'update']);

    Route::get('/car-settings/car-conditions', [CarConditionController::class, 'index']);
    Route::post('/car-settings/car-conditions', [CarConditionController::class, 'store']);
    Route::get('/car-settings/car-conditions/{car_condition}/edit', [CarConditionController::class, 'edit']);
    Route::patch('/car-settings/car-conditions/{car_condition}', [CarConditionController::class, 'update']);

    Route::get('/car-settings/dop-options', [CarOptionController::class, 'index']);
    Route::post('/car-settings/dop-options', [CarOptionController::class, 'store']);
    Route::get('/car-settings/dop-options/{option_id}/edit', [CarOptionController::class, 'edit']);
    Route::patch('/car-settings/dop-options/{option_id}', [CarOptionController::class, 'update']);
});


Route::group(['prefix' => 'auth'], function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:30,1');
});

// Public landing API — без авторизации
Route::prefix('landing')->group(function () {
    Route::get('cities',          [LandingController::class, 'cities']);
    Route::get('cities/default',  [LandingController::class, 'defaultCity']);
    Route::get('rental-tariffs', [LandingController::class, 'rentalTariffs']);
    Route::get('gearboxes',      [LandingController::class, 'gearboxes']);
    Route::get('fuel-types',     [LandingController::class, 'fuelTypes']);
    Route::get('car-brands',     [LandingController::class, 'carBrands']);
    Route::get('car-models',     [LandingController::class, 'carModels']);
    Route::get('body-types',     [LandingController::class, 'bodyTypes']);
    Route::get('colors',         [LandingController::class, 'colors']);
    Route::get('price-calc',     [LandingController::class, 'priceCalc']);
    Route::get('offers',         [LandingController::class, 'offers']);
    Route::get('offers/{id}',    [LandingController::class, 'offer']);
    Route::get('owners/{id}',    [LandingController::class, 'ownerProfile']);
    Route::post('apply/request-otp', [LandingController::class, 'requestApplyOtp'])->middleware('throttle:20,1');
    Route::post('apply',         [LandingController::class, 'apply'])->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Кабинет арендодателя — guard 'owner' (Sanctum)
|--------------------------------------------------------------------------
| Отдельный контур от админского 'api' (Passport): токен владельца не должен
| открывать админские ручки.
*/
Route::prefix('owner')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('request-otp',     [OwnerAuthController::class, 'requestOtp'])->middleware('throttle:20,1');
        Route::post('verify-otp',      [OwnerAuthController::class, 'verifyOtp'])->middleware('throttle:20,1');
        Route::post('login',           [OwnerAuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [OwnerAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
        Route::post('logout', [OwnerAuthController::class, 'logout'])->middleware('auth:owner');
    });

    Route::middleware(['auth:owner', 'owner.active'])->group(function () {

        // Справочники под поля ListingRequest — состояние, привод, объём двигателя, год, доп. опции
        Route::get('reference',       [OwnerReferenceController::class, 'index']);

        Route::get('me',              [OwnerProfileController::class, 'show']);
        Route::patch('me',            [OwnerProfileController::class, 'update']);
        Route::post('me/change-password',          [OwnerProfileController::class, 'changePassword']);
        Route::post('me/change-phone/request-otp', [OwnerProfileController::class, 'requestPhoneChange']);
        Route::post('me/change-phone/verify-otp',  [OwnerProfileController::class, 'confirmPhoneChange']);

        Route::get('listings',   [OwnerListingController::class, 'index']);
        Route::post('listings',  [OwnerListingController::class, 'store']);

        // owns.listing — первый рубеж проверки владения; второй внутри контроллеров.
        Route::middleware('owns.listing')->group(function () {
            Route::get('listings/{id}',         [OwnerListingController::class, 'show']);
            Route::patch('listings/{id}',       [OwnerListingController::class, 'update']);
            Route::delete('listings/{id}',      [OwnerListingController::class, 'destroy']);
            Route::post('listings/{id}/pause',    [OwnerListingController::class, 'pause']);
            Route::post('listings/{id}/publish',  [OwnerListingController::class, 'publish']);
            Route::post('listings/{id}/resubmit', [OwnerListingController::class, 'resubmit']);

            // Техпаспорт. Файлы лежат на приватном диске и наружу не отдаются:
            // в объявлении документы не показываются (ТЗ §4).
            Route::get('listings/{id}/documents',               [OwnerListingDocumentController::class, 'index']);
            Route::post('listings/{id}/documents',              [OwnerListingDocumentController::class, 'store']);
            Route::delete('listings/{id}/documents/{documentId}', [OwnerListingDocumentController::class, 'destroy']);

            Route::get('listings/{id}/photos',              [OwnerListingPhotoController::class, 'index']);
            Route::post('listings/{id}/photos',             [OwnerListingPhotoController::class, 'store']);
            Route::delete('listings/{id}/photos/{photoId}', [OwnerListingPhotoController::class, 'destroy']);

            Route::get('listings/{id}/unavailable-periods',              [OwnerAvailabilityController::class, 'index']);
            Route::post('listings/{id}/unavailable-periods',             [OwnerAvailabilityController::class, 'store']);
            Route::delete('listings/{id}/unavailable-periods/{periodId}', [OwnerAvailabilityController::class, 'destroy']);
        });

        Route::get('applications',          [OwnerApplicationController::class, 'index']);
        Route::get('applications/statuses', [OwnerApplicationController::class, 'statuses']);
        Route::get('applications/{id}',     [OwnerApplicationController::class, 'show']);
        Route::patch('applications/{id}',   [OwnerApplicationController::class, 'update']);
    });
});

/*
|--------------------------------------------------------------------------
| Модерация — guard 'api' (менеджер, Passport)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('moderation')->group(function () {
    Route::get('listings',             [ListingModerationController::class, 'index']);
    Route::get('listings/{id}',        [ListingModerationController::class, 'show']);
    Route::get('listings/{id}/logs',   [ListingModerationController::class, 'logs']);
    Route::post('listings/{id}/approve', [ListingModerationController::class, 'approve']);
    Route::post('listings/{id}/reject',  [ListingModerationController::class, 'reject']);

    // Платный подъём в топ выдачи, решение от 25.09.2026
    Route::post('listings/{id}/boost',   [ListingModerationController::class, 'boost']);
    Route::post('listings/{id}/unboost', [ListingModerationController::class, 'unboost']);

    // Сверка техпаспорта: единственное место, где выставляется vin_verified
    Route::get('listings/{id}/documents',                [DocumentReviewController::class, 'index']);
    Route::get('listings/{id}/documents/{documentId}',   [DocumentReviewController::class, 'show']);
    Route::post('listings/{id}/documents/verify',        [DocumentReviewController::class, 'verify']);
    Route::post('listings/{id}/documents/reject',        [DocumentReviewController::class, 'reject']);

    Route::get('owners',              [OwnerManagementController::class, 'index']);
    Route::get('owners/{id}',         [OwnerManagementController::class, 'show']);
    Route::post('owners/{id}/block',   [OwnerManagementController::class, 'block']);
    Route::post('owners/{id}/unblock', [OwnerManagementController::class, 'unblock']);
});
