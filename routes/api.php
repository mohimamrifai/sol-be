<?php

use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\CustomerDiscountController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Api\Admin\MasterDataController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\RoleManagementController;
use App\Http\Controllers\Api\Admin\ShipmentController as AdminShipmentController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VendorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Customer\BookingController as CustomerBookingController;
use App\Http\Controllers\Api\Customer\BranchController as CustomerBranchController;
use App\Http\Controllers\Api\Customer\CompanyController as CustomerCompanyController;
use App\Http\Controllers\Api\Customer\CompanyDocumentController as CustomerCompanyDocumentController;
use App\Http\Controllers\Api\Customer\CustomerLocationController;
use App\Http\Controllers\Api\Customer\DashboardController as CustomerDashboardController;
use App\Http\Controllers\Api\Customer\DocumentController as CustomerDocumentController;
use App\Http\Controllers\Api\Customer\InvoiceController as CustomerInvoiceController;
use App\Http\Controllers\Api\Customer\MasterDataReadController;
use App\Http\Controllers\Api\Customer\MyProfileController;
use App\Http\Controllers\Api\Customer\PaymentController as CustomerPaymentController;
use App\Http\Controllers\Api\Customer\RegistrationController;
use App\Http\Controllers\Api\Customer\ShipmentController as CustomerShipmentController;
use App\Http\Controllers\Api\Customer\UserController as CustomerUserController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\MasterMetadataController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\PublicBookingEstimateController;
use App\Http\Controllers\Api\PublicTrackingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes – SOL Backend
|--------------------------------------------------------------------------
*/

// ══════════════════════════════════════════════
//  PUBLIC (tanpa autentikasi)
// ══════════════════════════════════════════════
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
Route::post('/reset-password', [ForgotPasswordController::class, 'reset']);

Route::get('/register/check-company-code', [RegistrationController::class, 'checkCompanyCode']);
Route::get('/tracking', [PublicTrackingController::class, 'track'])->name('public.tracking');
Route::get('/tracking/consignment-note-pdf', [PublicTrackingController::class, 'consignmentNotePdf']);
Route::get('/tracking/waybill-pdf', [PublicTrackingController::class, 'waybillPdf']);

// Master data + estimasi biaya (tanpa login, untuk landing / halaman publik)
Route::prefix('public')->group(function () {
    Route::get('master/locations', [MasterDataReadController::class, 'locations']);
    Route::get('master/transport-modes', [MasterDataReadController::class, 'transportModes']);
    Route::get('master/service-types', [MasterDataReadController::class, 'serviceTypes']);
    Route::get('master/container-types', [MasterDataReadController::class, 'containerTypes']);
    Route::get('master/additional-services', [MasterDataReadController::class, 'additionalServices']);
    Route::get('master/cargo-categories', [MasterDataReadController::class, 'cargoCategories']);
    Route::get('master/dg-classes', [MasterDataReadController::class, 'dgClasses']);
    Route::get('master/additional-charges', [MasterDataReadController::class, 'additionalCharges']);
    Route::get('master/shipment-coverages', [MasterDataReadController::class, 'shipmentCoverages']);

    // Registration form metadata (dropdown options that live in code, not DB)
    Route::get('master/business-entity-types', [MasterMetadataController::class, 'businessEntityTypes']);
    Route::get('master/business-categories', [MasterMetadataController::class, 'businessCategories']);
    Route::get('master/monthly-shipment-estimates', [MasterMetadataController::class, 'monthlyShipmentEstimates']);
    Route::post('bookings/estimate-price', [PublicBookingEstimateController::class, 'estimate']);
});
// Midtrans notification (no auth - called by Midtrans)
Route::post('/payments/midtrans/notification', [MidtransWebhookController::class, 'notification']);

// ══════════════════════════════════════════════
//  AUTHENTICATED (memerlukan Sanctum token)
// ══════════════════════════════════════════════
Route::middleware('auth:sanctum')->group(function () {

    // ── Auth & Profil ──
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // ══════════════════════════════════════════
    //  ADMIN INTERNAL
    // ══════════════════════════════════════════
    Route::prefix('admin')->middleware('admin')->group(function () {

        Route::get('dashboard', [AdminDashboardController::class, 'index']);

        // Customer Management
        Route::apiResource('companies', CompanyController::class);
        Route::post('companies/{company}/approve', [CompanyController::class, 'approve']);
        Route::post('companies/{company}/reject', [CompanyController::class, 'reject']);
        // Branch Management (nested under company)
        Route::get('companies/{company}/branches', [BranchController::class, 'index']);
        Route::post('companies/{company}/branches', [BranchController::class, 'store']);
        Route::get('companies/{company}/branches/{branch}', [BranchController::class, 'show']);
        Route::put('companies/{company}/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('companies/{company}/branches/{branch}', [BranchController::class, 'destroy']);
        // Customer Discount Management (nested under company)
        Route::get('companies/{company}/customer-discounts', [CustomerDiscountController::class, 'index']);
        Route::post('companies/{company}/customer-discounts', [CustomerDiscountController::class, 'store']);
        Route::get('companies/{company}/customer-discounts/{customerDiscount}', [CustomerDiscountController::class, 'show']);
        Route::put('companies/{company}/customer-discounts/{customerDiscount}', [CustomerDiscountController::class, 'update']);
        Route::delete('companies/{company}/customer-discounts/{customerDiscount}', [CustomerDiscountController::class, 'destroy']);

        // User Management
        Route::apiResource('users', UserController::class);

        // Role & Permission Management
        Route::get('roles', [RoleManagementController::class, 'index']);
        Route::post('roles', [RoleManagementController::class, 'storeRole']);
        Route::get('permissions', [RoleManagementController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleManagementController::class, 'updateRolePermissions']);

        // Master Data – Locations
        Route::get('locations', [MasterDataController::class, 'locations']);
        Route::post('locations', [MasterDataController::class, 'storeLocation']);
        Route::put('locations/{location}', [MasterDataController::class, 'updateLocation']);
        Route::delete('locations/{location}', [MasterDataController::class, 'destroyLocation']);

        // Master Data – Transport Modes
        Route::get('transport-modes', [MasterDataController::class, 'transportModes']);
        Route::post('transport-modes', [MasterDataController::class, 'storeTransportMode']);
        Route::put('transport-modes/{transportMode}', [MasterDataController::class, 'updateTransportMode']);
        Route::delete('transport-modes/{transportMode}', [MasterDataController::class, 'destroyTransportMode']);

        // Master Data – Service Types
        Route::get('service-types', [MasterDataController::class, 'serviceTypes']);
        Route::post('service-types', [MasterDataController::class, 'storeServiceType']);
        Route::put('service-types/{serviceType}', [MasterDataController::class, 'updateServiceType']);
        Route::delete('service-types/{serviceType}', [MasterDataController::class, 'destroyServiceType']);

        // Master Data – Container Types
        Route::get('container-types', [MasterDataController::class, 'containerTypes']);
        Route::post('container-types', [MasterDataController::class, 'storeContainerType']);
        Route::put('container-types/{containerType}', [MasterDataController::class, 'updateContainerType']);
        Route::delete('container-types/{containerType}', [MasterDataController::class, 'destroyContainerType']);

        // Master Data – Additional Services
        Route::get('additional-services', [MasterDataController::class, 'additionalServices']);
        Route::post('additional-services', [MasterDataController::class, 'storeAdditionalService']);
        Route::put('additional-services/{additionalService}', [MasterDataController::class, 'updateAdditionalService']);
        Route::delete('additional-services/{additionalService}', [MasterDataController::class, 'destroyAdditionalService']);

        // Master Data – Trains (Rail)
        Route::get('trains', [MasterDataController::class, 'trains']);
        Route::post('trains', [MasterDataController::class, 'storeTrain']);
        Route::put('trains/{train}', [MasterDataController::class, 'updateTrain']);
        Route::delete('trains/{train}', [MasterDataController::class, 'destroyTrain']);

        // Master Data – Train Cars (Gerbong)
        Route::get('train-cars', [MasterDataController::class, 'trainCars']);
        Route::post('train-cars', [MasterDataController::class, 'storeTrainCar']);
        Route::put('train-cars/{trainCar}', [MasterDataController::class, 'updateTrainCar']);
        Route::delete('train-cars/{trainCar}', [MasterDataController::class, 'destroyTrainCar']);

        // Master Data – Cargo Categories
        Route::get('cargo-categories', [MasterDataController::class, 'cargoCategories']);
        Route::post('cargo-categories', [MasterDataController::class, 'storeCargoCategory']);
        Route::put('cargo-categories/{cargoCategory}', [MasterDataController::class, 'updateCargoCategory']);
        Route::delete('cargo-categories/{cargoCategory}', [MasterDataController::class, 'destroyCargoCategory']);

        // Master Data – DG Classes
        Route::get('dg-classes', [MasterDataController::class, 'dgClasses']);
        Route::post('dg-classes', [MasterDataController::class, 'storeDgClass']);
        Route::put('dg-classes/{dgClass}', [MasterDataController::class, 'updateDgClass']);
        Route::delete('dg-classes/{dgClass}', [MasterDataController::class, 'destroyDgClass']);

        // Master Data – Additional Charges
        Route::get('additional-charges', [MasterDataController::class, 'additionalCharges']);
        Route::post('additional-charges', [MasterDataController::class, 'storeAdditionalCharge']);
        Route::put('additional-charges/{additionalCharge}', [MasterDataController::class, 'updateAdditionalCharge']);
        Route::delete('additional-charges/{additionalCharge}', [MasterDataController::class, 'destroyAdditionalCharge']);

        // Booking Management
        Route::get('bookings', [AdminBookingController::class, 'index']);
        Route::post('bookings', [AdminBookingController::class, 'store']);
        Route::post('bookings/estimate-price', [AdminBookingController::class, 'estimatePrice']);
        Route::get('bookings/{booking}', [AdminBookingController::class, 'show']);
        Route::put('bookings/{booking}', [AdminBookingController::class, 'update']);
        Route::post('bookings/{booking}/approve', [AdminBookingController::class, 'approve']);
        Route::post('bookings/{booking}/reject', [AdminBookingController::class, 'reject']);
        Route::post('bookings/{booking}/convert-to-shipment', [AdminBookingController::class, 'convertToShipment']);

        // Shipment Management
        Route::get('shipments', [AdminShipmentController::class, 'index']);
        Route::get('shipments/{shipment}', [AdminShipmentController::class, 'show']);
        Route::put('shipments/{shipment}', [AdminShipmentController::class, 'update']);
        Route::post('shipments/{shipment}/tracking', [AdminShipmentController::class, 'updateTracking']);
        Route::post('shipments/{shipment}/containers', [AdminShipmentController::class, 'addContainer']);
        Route::put('containers/{container}', [AdminShipmentController::class, 'updateContainer']);
        Route::delete('containers/{container}', [AdminShipmentController::class, 'destroyContainer']);
        Route::post('containers/{container}/racks', [AdminShipmentController::class, 'addRack']);
        Route::put('racks/{rack}', [AdminShipmentController::class, 'updateRack']);
        Route::delete('racks/{rack}', [AdminShipmentController::class, 'destroyRack']);
        Route::post('shipments/{shipment}/items', [AdminShipmentController::class, 'addItem']);
        Route::put('shipment-items/{item}', [AdminShipmentController::class, 'updateItem']);
        Route::delete('shipment-items/{item}', [AdminShipmentController::class, 'destroyItem']);
        Route::get('shipments/{shipment}/consignment-note-pdf', [AdminShipmentController::class, 'downloadConsignmentNotePdf']);
        Route::get('shipments/{shipment}/waybill-pdf', [AdminShipmentController::class, 'downloadWaybillPdf']);

        // Invoice Management
        Route::get('invoices', [AdminInvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::get('invoices/{invoice}/pdf', [AdminInvoiceController::class, 'downloadPdf']);
        Route::post('invoices', [AdminInvoiceController::class, 'store']);
        Route::put('invoices/{invoice}', [AdminInvoiceController::class, 'update']);
        Route::delete('invoices/{invoice}', [AdminInvoiceController::class, 'destroy']);
        Route::post('invoices/{invoice}/generate-payment-link', [AdminPaymentController::class, 'generatePaymentLink']);

        // Payment / AR Management
        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::get('payments/overdue-invoices', [AdminPaymentController::class, 'overdueInvoices']);
        Route::post('payments/{payment}/sync-midtrans', [AdminPaymentController::class, 'syncMidtrans']);
        Route::post('payments/{payment}/verify-manual', [AdminPaymentController::class, 'verifyManual']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);

        // Vendor & Pricing Management
        Route::apiResource('vendors', VendorController::class);
        Route::post('vendors/{vendor}/services', [VendorController::class, 'storeService']);
        Route::post('vendor-services/{vendorService}/pricings', [VendorController::class, 'storePricing']);
        Route::put('pricings/{pricing}', [VendorController::class, 'updatePricing']);
        Route::delete('pricings/{pricing}', [VendorController::class, 'destroyPricing']);
    });

    // ══════════════════════════════════════════
    //  CUSTOMER PORTAL
    // ══════════════════════════════════════════
    Route::prefix('customer')->middleware('customer')->group(function () {

        // Dashboard (ringkasan untuk halaman Dashboard Customer Portal)
        Route::get('dashboard', [CustomerDashboardController::class, 'index']);

        // Master data (read-only, untuk form booking)
        Route::get('master/locations', [MasterDataReadController::class, 'locations']);
        Route::get('master/transport-modes', [MasterDataReadController::class, 'transportModes']);
        Route::get('master/service-types', [MasterDataReadController::class, 'serviceTypes']);
        Route::get('master/container-types', [MasterDataReadController::class, 'containerTypes']);
        Route::get('master/additional-services', [MasterDataReadController::class, 'additionalServices']);
        Route::get('master/cargo-categories', [MasterDataReadController::class, 'cargoCategories']);
        Route::get('master/dg-classes', [MasterDataReadController::class, 'dgClasses']);
        Route::get('master/additional-charges', [MasterDataReadController::class, 'additionalCharges']);
        Route::get('master/shipment-coverages', [MasterDataReadController::class, 'shipmentCoverages']);

        Route::get('branches', [CustomerBranchController::class, 'index']);

        // Booking
        Route::post('bookings/estimate-price', [CustomerBookingController::class, 'estimatePrice']);
        Route::get('bookings/stats', [CustomerBookingController::class, 'stats']);
        Route::get('bookings', [CustomerBookingController::class, 'index']);
        Route::post('bookings', [CustomerBookingController::class, 'store']);
        Route::get('bookings/{booking}', [CustomerBookingController::class, 'show']);
        Route::put('bookings/{booking}', [CustomerBookingController::class, 'update']);
        Route::post('bookings/{booking}/submit', [CustomerBookingController::class, 'submit']);
        Route::post('bookings/{booking}/cancel', [CustomerBookingController::class, 'cancel']);
        Route::post('bookings/{booking}/duplicate', [CustomerBookingController::class, 'duplicate']);
        Route::get('bookings/{booking}/activities', [CustomerBookingController::class, 'activities']);
        Route::post('bookings/{booking}/attachments', [CustomerBookingController::class, 'uploadAttachment']);
        Route::delete('bookings/{booking}/attachments/{attachment}', [CustomerBookingController::class, 'deleteAttachment']);

        // Shipment
        Route::get('shipments/stats', [CustomerShipmentController::class, 'stats']);
        Route::get('shipments', [CustomerShipmentController::class, 'index']);
        Route::get('shipments/{shipment}', [CustomerShipmentController::class, 'show']);
        Route::get('shipments/{shipment}/consignment-note-pdf', [CustomerShipmentController::class, 'downloadConsignmentNotePdf']);
        Route::get('shipments/{shipment}/waybill-pdf', [CustomerShipmentController::class, 'downloadWaybillPdf']);

        // Invoice
        Route::get('invoices/stats', [CustomerInvoiceController::class, 'stats']);
        Route::get('invoices', [CustomerInvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [CustomerInvoiceController::class, 'show']);
        Route::get('invoices/{invoice}/pdf', [CustomerInvoiceController::class, 'downloadPdf']);
        // Payment
        Route::get('payments/stats', [CustomerPaymentController::class, 'stats']);
        Route::get('payments', [CustomerPaymentController::class, 'index']);
        Route::get('payments/{payment}', [CustomerPaymentController::class, 'show']);
        Route::post('payments/{payment}/sync-midtrans', [CustomerPaymentController::class, 'syncMidtrans']);
        Route::post('payments/{payment}/manual-submit', [CustomerPaymentController::class, 'manualSubmit']);
        Route::get('payments/{payment}/receipt', [CustomerPaymentController::class, 'receipt']);
        Route::get('payments/{payment}/proof-preview', [CustomerPaymentController::class, 'proofPreview']);
        Route::get('payments/{payment}/proof-download', [CustomerPaymentController::class, 'proofDownload']);
        Route::post('invoices/{invoice}/pay', [CustomerPaymentController::class, 'pay']);

        // Documents (virtual aggregation across booking, shipment, billing)
        Route::get('documents/stats', [CustomerDocumentController::class, 'stats']);
        Route::get('documents/shipment-options', [CustomerDocumentController::class, 'shipmentOptions']);
        Route::get('documents', [CustomerDocumentController::class, 'index']);
        Route::get('documents/{id}', [CustomerDocumentController::class, 'show']);
        Route::get('documents/{id}/preview', [CustomerDocumentController::class, 'preview']);
        Route::get('documents/{id}/download', [CustomerDocumentController::class, 'download']);

        // Company Settings
        Route::get('company', [CustomerCompanyController::class, 'show'])->name('customer.company.show');
        Route::put('company', [CustomerCompanyController::class, 'update'])->name('customer.company.update');
        Route::get('company/commercial', [CustomerCompanyController::class, 'commercial'])->name('customer.company.commercial');
        Route::get('company/activities', [CustomerCompanyController::class, 'activities'])->name('customer.company.activities');

        // Company Documents
        Route::get('company/documents', [CustomerCompanyDocumentController::class, 'index'])->name('customer.company.documents.index');
        Route::post('company/documents', [CustomerCompanyDocumentController::class, 'store'])->name('customer.company.documents.store');
        Route::get('company/documents/{document}', [CustomerCompanyDocumentController::class, 'show'])->name('customer.company.documents.show');
        Route::get('company/documents/{document}/download', [CustomerCompanyDocumentController::class, 'download'])->name('customer.company.documents.download');
        Route::delete('company/documents/{document}', [CustomerCompanyDocumentController::class, 'destroy'])->name('customer.company.documents.destroy');

        // Locations
        Route::get('locations/stats', [CustomerLocationController::class, 'stats'])->name('customer.locations.stats');
        Route::get('locations', [CustomerLocationController::class, 'index'])->name('customer.locations.index');
        Route::post('locations', [CustomerLocationController::class, 'store'])->name('customer.locations.store');
        Route::get('locations/{location}', [CustomerLocationController::class, 'show'])->name('customer.locations.show');
        Route::put('locations/{location}', [CustomerLocationController::class, 'update'])->name('customer.locations.update');
        Route::patch('locations/{location}/status', [CustomerLocationController::class, 'changeStatus'])->name('customer.locations.status');
        Route::get('locations/{location}/activities', [CustomerLocationController::class, 'activities'])->name('customer.locations.activities');

        // User Management
        Route::get('users/stats', [CustomerUserController::class, 'stats'])->name('customer.users.stats');
        Route::get('users', [CustomerUserController::class, 'index'])->name('customer.users.index');
        Route::post('users', [CustomerUserController::class, 'store'])->name('customer.users.store');
        Route::get('users/{user}', [CustomerUserController::class, 'show'])->name('customer.users.show');
        Route::put('users/{user}', [CustomerUserController::class, 'update'])->name('customer.users.update');
        Route::patch('users/{user}/status', [CustomerUserController::class, 'changeStatus'])->name('customer.users.status');
        Route::patch('users/{user}/role', [CustomerUserController::class, 'changeRole'])->name('customer.users.role');
        Route::post('users/{user}/reset-password', [CustomerUserController::class, 'resetPassword'])->name('customer.users.reset-password');
        Route::get('users/{user}/activities', [CustomerUserController::class, 'activities'])->name('customer.users.activities');

        // My Profile
        Route::get('my-profile', [MyProfileController::class, 'show'])->name('customer.my-profile.show');
        Route::put('my-profile', [MyProfileController::class, 'update'])->name('customer.my-profile.update');
        Route::post('my-profile/photo', [MyProfileController::class, 'uploadPhoto'])->name('customer.my-profile.photo.upload');
        Route::delete('my-profile/photo', [MyProfileController::class, 'deletePhoto'])->name('customer.my-profile.photo.delete');
        Route::post('my-profile/change-password', [MyProfileController::class, 'changePassword'])->name('customer.my-profile.change-password');
    });
});
