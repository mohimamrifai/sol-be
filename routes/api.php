<?php

use App\Http\Controllers\Api\Admin\AdminContainerController;
use App\Http\Controllers\Api\Admin\AdminContainerMovementController;
use App\Http\Controllers\Api\Admin\AdminCustomerPricingController;
use App\Http\Controllers\Api\Admin\AdminOperationTaskController;
use App\Http\Controllers\Api\Admin\AdminProofOfDeliveryController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\Admin\AdminRouteController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminStationController;
use App\Http\Controllers\Api\Admin\AdminVendorInvoiceController;
use App\Http\Controllers\Api\Admin\AdminVendorJobOrderController;
use App\Http\Controllers\Api\Admin\AdminVendorPaymentController;
use App\Http\Controllers\Api\Admin\AdminVendorReportController;
use App\Http\Controllers\Api\Admin\AdminTrainScheduleController;
use App\Http\Controllers\Api\Admin\AdminYardController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\BranchController;
use App\Http\Controllers\Api\Admin\CompanyController;
use App\Http\Controllers\Api\Admin\CustomerDiscountController;
use App\Http\Controllers\Api\Admin\CustomerLocationController as AdminCustomerLocationController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Api\Admin\MasterDataController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\PricingController;
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
use App\Http\Controllers\Api\Vendor\DashboardController;
use App\Http\Controllers\Api\Vendor\DocumentController;
use App\Http\Controllers\Api\Vendor\JobOrderController;
use App\Http\Controllers\Api\Vendor\VendorInvoiceController;
use App\Http\Controllers\Api\Vendor\VendorPaymentController;
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
    Route::prefix('admin')->middleware(['admin', 'feature'])->group(function () {

        Route::get('dashboard', [AdminDashboardController::class, 'index']);

        // Customer Management
        Route::get('companies/stats', [CompanyController::class, 'stats']);
        Route::apiResource('companies', CompanyController::class);
        Route::post('companies/{company}/approve', [CompanyController::class, 'approve']);
        Route::post('companies/{company}/reject', [CompanyController::class, 'reject']);
        Route::post('companies/{company}/suspend', [CompanyController::class, 'suspend']);
        Route::get('companies/{company}/locations', [AdminCustomerLocationController::class, 'index']);
        Route::post('companies/{company}/locations', [AdminCustomerLocationController::class, 'store']);
        Route::put('companies/{company}/locations/{location}', [AdminCustomerLocationController::class, 'update']);
        Route::post('companies/{company}/locations/{location}/status', [AdminCustomerLocationController::class, 'changeStatus']);
        Route::delete('companies/{company}/locations/{location}', [AdminCustomerLocationController::class, 'destroy']);
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
        Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);

        // Role & Permission Management
        Route::get('roles/stats', [RoleManagementController::class, 'stats']);
        Route::get('roles', [RoleManagementController::class, 'index']);
        Route::post('roles', [RoleManagementController::class, 'storeRole']);
        Route::get('roles/{role}', [RoleManagementController::class, 'show']);
        Route::put('roles/{role}', [RoleManagementController::class, 'updateRole']);
        Route::post('roles/{role}/deactivate', [RoleManagementController::class, 'deactivate']);
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
        Route::get('service-types/stats', [MasterDataController::class, 'serviceTypesStats']);
        Route::get('service-types', [MasterDataController::class, 'serviceTypes']);
        Route::post('service-types', [MasterDataController::class, 'storeServiceType']);
        Route::put('service-types/{serviceType}', [MasterDataController::class, 'updateServiceType']);
        Route::delete('service-types/{serviceType}', [MasterDataController::class, 'destroyServiceType']);

        // Master Data – Container Types
        Route::get('container-types/stats', [MasterDataController::class, 'containerTypesStats']);
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
        Route::get('additional-charges/stats', [MasterDataController::class, 'additionalChargesStats']);
        Route::get('additional-charges', [MasterDataController::class, 'additionalCharges']);
        Route::get('additional-charges/{additionalCharge}', [MasterDataController::class, 'showAdditionalCharge']);
        Route::post('additional-charges', [MasterDataController::class, 'storeAdditionalCharge']);
        Route::put('additional-charges/{additionalCharge}', [MasterDataController::class, 'updateAdditionalCharge']);
        Route::post('additional-charges/{additionalCharge}/deactivate', [MasterDataController::class, 'deactivateAdditionalCharge']);

        // Booking Management
        Route::get('bookings/stats', [AdminBookingController::class, 'stats']);
        Route::get('bookings', [AdminBookingController::class, 'index']);
        Route::post('bookings', [AdminBookingController::class, 'store']);
        Route::post('bookings/estimate-price', [AdminBookingController::class, 'estimatePrice']);
        Route::get('bookings/{booking}', [AdminBookingController::class, 'show']);
        Route::put('bookings/{booking}', [AdminBookingController::class, 'update']);
        Route::delete('bookings/{booking}', [AdminBookingController::class, 'destroy']);
        Route::post('bookings/{booking}/submit', [AdminBookingController::class, 'submit']);
        Route::post('bookings/{booking}/approve', [AdminBookingController::class, 'approve']);
        Route::post('bookings/{booking}/confirm', [AdminBookingController::class, 'confirm']);
        Route::post('bookings/{booking}/reject', [AdminBookingController::class, 'reject']);
        Route::post('bookings/{booking}/convert-to-shipment', [AdminBookingController::class, 'convertToShipment']);
        Route::post('bookings/{booking}/duplicate', [AdminBookingController::class, 'duplicate']);
        Route::post('bookings/{booking}/attachments', [AdminBookingController::class, 'uploadAttachment']);
        Route::delete('bookings/{booking}/attachments/{attachment}', [AdminBookingController::class, 'deleteAttachment']);

        // Shipment Management
        Route::get('shipments/stats', [AdminShipmentController::class, 'stats']);
        Route::get('shipments', [AdminShipmentController::class, 'index']);
        Route::get('shipments/{shipment}', [AdminShipmentController::class, 'show']);
        Route::put('shipments/{shipment}', [AdminShipmentController::class, 'update']);
        Route::post('shipments/{shipment}/ready-for-departure', [AdminShipmentController::class, 'readyForDeparture']);
        Route::post('shipments/{shipment}/cancel', [AdminShipmentController::class, 'cancelShipment']);
        Route::post('shipments/{shipment}/tracking', [AdminShipmentController::class, 'updateTracking']);
        Route::post('shipments/{shipment}/containers', [AdminShipmentController::class, 'addContainer']);
        Route::get('shipments/{shipment}/available-containers', [AdminShipmentController::class, 'availableContainers']);
        Route::post('shipments/{shipment}/containers/{container}/assign', [AdminShipmentController::class, 'assignContainerSlot']);
        Route::post('shipments/{shipment}/register-vendor-container', [AdminShipmentController::class, 'registerVendorContainer']);
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
        Route::get('invoices/stats', [AdminInvoiceController::class, 'stats']);
        Route::get('invoices/eligible-shipments', [AdminInvoiceController::class, 'eligibleShipments']);
        Route::get('shipments/{shipment}/invoice-preview', [AdminInvoiceController::class, 'previewLineItems']);
        Route::post('shipments/{shipment}/generate-invoice', [AdminInvoiceController::class, 'generateFromShipment']);
        Route::get('invoices', [AdminInvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [AdminInvoiceController::class, 'show']);
        Route::get('invoices/{invoice}/pdf', [AdminInvoiceController::class, 'downloadPdf']);
        Route::post('invoices', [AdminInvoiceController::class, 'store']);
        Route::put('invoices/{invoice}', [AdminInvoiceController::class, 'update']);
        Route::post('invoices/{invoice}/issue', [AdminInvoiceController::class, 'issue']);
        Route::delete('invoices/{invoice}', [AdminInvoiceController::class, 'destroy']);
        Route::post('invoices/{invoice}/generate-payment-link', [AdminPaymentController::class, 'generatePaymentLink']);

        // Payment / AR Management
        Route::get('payments/stats', [AdminPaymentController::class, 'stats']);
        Route::get('payments/options', [AdminPaymentController::class, 'paymentOptions']);
        Route::get('payments/eligible-invoices', [AdminPaymentController::class, 'eligibleInvoices']);
        Route::get('payments', [AdminPaymentController::class, 'index']);
        Route::get('payments/overdue-invoices', [AdminPaymentController::class, 'overdueInvoices']);
        Route::post('invoices/{invoice}/record-payment', [AdminPaymentController::class, 'recordPayment']);
        Route::post('payments/{payment}/sync-midtrans', [AdminPaymentController::class, 'syncMidtrans']);
        Route::post('payments/{payment}/verify-manual', [AdminPaymentController::class, 'verifyManual']);
        Route::get('payments/{payment}/receipt', [AdminPaymentController::class, 'receipt']);
        Route::get('payments/{payment}', [AdminPaymentController::class, 'show']);

        // Vendor & Pricing Management
        Route::get('vendors/stats', [VendorController::class, 'stats']);
        Route::post('vendors/{vendor}/deactivate', [VendorController::class, 'deactivate']);
        Route::post('vendors/{vendor}/contacts', [VendorController::class, 'storeContact']);
        Route::put('vendors/{vendor}/contacts/{contact}', [VendorController::class, 'updateContact']);
        Route::delete('vendors/{vendor}/contacts/{contact}', [VendorController::class, 'destroyContact']);
        Route::apiResource('vendors', VendorController::class);
        Route::post('vendors/{vendor}/services', [VendorController::class, 'storeService']);
        Route::post('vendor-services/{vendorService}/pricings', [VendorController::class, 'storePricing']);
        Route::get('pricings/stats', [PricingController::class, 'stats']);
        Route::get('pricings', [PricingController::class, 'index']);
        Route::post('pricings', [PricingController::class, 'store']);
        Route::get('pricings/{pricing}', [PricingController::class, 'show']);
        Route::post('pricings/{pricing}/deactivate', [PricingController::class, 'deactivate']);
        Route::put('pricings/{pricing}', [VendorController::class, 'updatePricing']);
        Route::delete('pricings/{pricing}', [VendorController::class, 'destroyPricing']);

        // Admin Vendor Job Order / Invoice / Payment (FSD)
        Route::get('vendor-job-orders/stats', [AdminVendorJobOrderController::class, 'stats']);
        Route::get('vendor-job-orders', [AdminVendorJobOrderController::class, 'index']);
        Route::get('vendor-job-orders/{vendorJobOrder}', [AdminVendorJobOrderController::class, 'show']);
        Route::put('vendor-job-orders/{vendorJobOrder}', [AdminVendorJobOrderController::class, 'update']);
        Route::post('vendor-job-orders/{vendorJobOrder}/send', [AdminVendorJobOrderController::class, 'send']);
        Route::post('vendor-job-orders/{vendorJobOrder}/verify-completion', [AdminVendorJobOrderController::class, 'verifyCompletion']);
        Route::post('vendor-job-orders/{vendorJobOrder}/documents', [AdminVendorJobOrderController::class, 'storeDocument']);
        Route::get('vendor-job-orders/{vendorJobOrder}/documents/{document}', [AdminVendorJobOrderController::class, 'downloadDocument']);
        Route::get('vendor-job-orders/{vendorJobOrder}/pdf', [AdminVendorJobOrderController::class, 'pdf']);

        Route::get('vendor-invoices/stats', [AdminVendorInvoiceController::class, 'stats']);
        Route::get('vendor-invoices/eligible-job-orders', [AdminVendorInvoiceController::class, 'eligibleJobOrders']);
        Route::get('vendor-invoices', [AdminVendorInvoiceController::class, 'index']);
        Route::post('vendor-invoices', [AdminVendorInvoiceController::class, 'store']);
        Route::get('vendor-invoices/{vendorInvoice}', [AdminVendorInvoiceController::class, 'show']);
        Route::post('vendor-invoices/{vendorInvoice}/start-verification', [AdminVendorInvoiceController::class, 'startVerification']);
        Route::post('vendor-invoices/{vendorInvoice}/verify', [AdminVendorInvoiceController::class, 'verify']);
        Route::post('vendor-invoices/{vendorInvoice}/reject', [AdminVendorInvoiceController::class, 'reject']);
        Route::post('vendor-invoices/{vendorInvoice}/attachments', [AdminVendorInvoiceController::class, 'storeAttachment']);

        Route::get('vendor-payments/stats', [AdminVendorPaymentController::class, 'stats']);
        Route::get('vendor-payments/company-banks', [AdminVendorPaymentController::class, 'companyBanks']);
        Route::get('vendor-payments', [AdminVendorPaymentController::class, 'index']);
        Route::get('vendor-payments/{vendorPaymentRequest}', [AdminVendorPaymentController::class, 'show']);
        Route::get('vendor-payments/{vendorPaymentRequest}/voucher', [AdminVendorPaymentController::class, 'voucher']);
        Route::post('vendor-payments/{vendorPaymentRequest}/approve', [AdminVendorPaymentController::class, 'approve']);
        Route::post('vendor-payments/{vendorPaymentRequest}/reject', [AdminVendorPaymentController::class, 'reject']);
        Route::post('vendor-payments/{vendorPaymentRequest}/record-payment', [AdminVendorPaymentController::class, 'recordPayment']);
        Route::post('vendor-payments/{vendorPaymentRequest}/documents', [AdminVendorPaymentController::class, 'storeDocument']);
        Route::get('vendor-payments/{vendorPaymentRequest}/documents/{document}', [AdminVendorPaymentController::class, 'downloadDocument']);

        Route::get('reports/vendor-invoices', [AdminVendorReportController::class, 'invoiceIndex']);
        Route::get('reports/vendor-invoices/export', [AdminVendorReportController::class, 'invoiceExport']);
        Route::get('reports/vendor-payments', [AdminVendorReportController::class, 'paymentIndex']);
        Route::get('reports/vendor-payments/export', [AdminVendorReportController::class, 'paymentExport']);

        // Container Management (FSD Phase 4)
        Route::get('containers/stats', [AdminContainerController::class, 'stats']);
        Route::get('containers', [AdminContainerController::class, 'index']);
        Route::post('containers', [AdminContainerController::class, 'store']);
        Route::get('containers/{containerAsset}', [AdminContainerController::class, 'show']);
        Route::put('containers/{containerAsset}', [AdminContainerController::class, 'update']);
        Route::get('container-movements', [AdminContainerMovementController::class, 'index']);

        // Operations (FSD Phase 5)
        Route::get('operation-tasks/{operationType}/stats', [AdminOperationTaskController::class, 'stats']);
        Route::get('operation-tasks/{operationType}', [AdminOperationTaskController::class, 'index']);
        Route::get('operation-tasks/task/{operationTask}', [AdminOperationTaskController::class, 'show']);
        Route::post('operation-tasks/{operationTask}/start', [AdminOperationTaskController::class, 'start']);
        Route::post('operation-tasks/{operationTask}/complete', [AdminOperationTaskController::class, 'complete']);
        Route::put('operation-tasks/{operationTask}/remark', [AdminOperationTaskController::class, 'updateRemark']);
        Route::post('operation-tasks/{operationTask}/documents', [AdminOperationTaskController::class, 'storeDocument']);

        // Proof of Delivery (FSD dedicated workflow)
        Route::get('proof-of-deliveries/stats', [AdminProofOfDeliveryController::class, 'stats']);
        Route::get('proof-of-deliveries', [AdminProofOfDeliveryController::class, 'index']);
        Route::get('proof-of-deliveries/{proofOfDelivery}', [AdminProofOfDeliveryController::class, 'show']);
        Route::post('proof-of-deliveries/{proofOfDelivery}/submit', [AdminProofOfDeliveryController::class, 'submit']);
        Route::post('proof-of-deliveries/{proofOfDelivery}/verify', [AdminProofOfDeliveryController::class, 'verify']);
        Route::post('proof-of-deliveries/{proofOfDelivery}/reject', [AdminProofOfDeliveryController::class, 'reject']);

        // Master Data – Routes, Stations, Yards (FSD Phase 6)
        Route::get('routes/stats', [AdminRouteController::class, 'stats']);
        Route::get('routes', [AdminRouteController::class, 'index']);
        Route::post('routes', [AdminRouteController::class, 'store']);
        Route::get('routes/{route}', [AdminRouteController::class, 'show']);
        Route::put('routes/{route}', [AdminRouteController::class, 'update']);
        Route::post('routes/{route}/deactivate', [AdminRouteController::class, 'deactivate']);

        Route::get('stations/stats', [AdminStationController::class, 'stats']);
        Route::get('stations', [AdminStationController::class, 'index']);
        Route::post('stations', [AdminStationController::class, 'store']);
        Route::get('stations/{station}', [AdminStationController::class, 'show']);
        Route::put('stations/{station}', [AdminStationController::class, 'update']);
        Route::post('stations/{station}/deactivate', [AdminStationController::class, 'deactivate']);

        Route::get('yards/stats', [AdminYardController::class, 'stats']);
        Route::get('yards', [AdminYardController::class, 'index']);
        Route::post('yards', [AdminYardController::class, 'store']);
        Route::get('yards/{yard}', [AdminYardController::class, 'show']);
        Route::put('yards/{yard}', [AdminYardController::class, 'update']);
        Route::post('yards/{yard}/deactivate', [AdminYardController::class, 'deactivate']);

        Route::get('train-schedules/stats', [AdminTrainScheduleController::class, 'stats']);
        Route::get('train-schedules', [AdminTrainScheduleController::class, 'index']);
        Route::post('train-schedules', [AdminTrainScheduleController::class, 'store']);
        Route::get('train-schedules/{trainSchedule}', [AdminTrainScheduleController::class, 'show']);
        Route::put('train-schedules/{trainSchedule}', [AdminTrainScheduleController::class, 'update']);
        Route::post('train-schedules/{trainSchedule}/cancel', [AdminTrainScheduleController::class, 'cancel']);

        Route::get('customer-pricings/stats', [AdminCustomerPricingController::class, 'stats']);
        Route::get('customer-pricings', [AdminCustomerPricingController::class, 'index']);
        Route::post('customer-pricings', [AdminCustomerPricingController::class, 'store']);
        Route::get('customer-pricings/{customerPricing}', [AdminCustomerPricingController::class, 'show']);
        Route::put('customer-pricings/{customerPricing}', [AdminCustomerPricingController::class, 'update']);
        Route::post('customer-pricings/{customerPricing}/deactivate', [AdminCustomerPricingController::class, 'deactivate']);

        // Reports (FSD Phase 7)
        Route::get('reports/shipments', [AdminReportController::class, 'shipmentReport']);
        Route::get('reports/shipments/export', [AdminReportController::class, 'shipmentReportExport']);
        Route::get('reports/bookings', [AdminReportController::class, 'bookingReport']);
        Route::get('reports/bookings/export', [AdminReportController::class, 'bookingReportExport']);
        Route::get('reports/customer-invoices', [AdminReportController::class, 'customerInvoiceReport']);
        Route::get('reports/customer-invoices/export', [AdminReportController::class, 'customerInvoiceReportExport']);
        Route::get('reports/customer-payments', [AdminReportController::class, 'customerPaymentReport']);
        Route::get('reports/customer-payments/export', [AdminReportController::class, 'customerPaymentReportExport']);
        Route::get('reports/containers', [AdminReportController::class, 'containerReport']);
        Route::get('reports/containers/export', [AdminReportController::class, 'containerReportExport']);

        // Settings (FSD Phase 8)
        Route::get('settings/profile', [AdminSettingsController::class, 'profile']);
        Route::post('settings/change-password', [AdminSettingsController::class, 'changePassword']);
        Route::get('settings/numbering-formats', [AdminSettingsController::class, 'numberingFormatsIndex']);
        Route::post('settings/numbering-formats/preview', [AdminSettingsController::class, 'numberingFormatPreview']);
        Route::get('settings/numbering-formats/{numberingFormat}', [AdminSettingsController::class, 'numberingFormatShow']);
        Route::put('settings/numbering-formats/{numberingFormat}', [AdminSettingsController::class, 'numberingFormatUpdate']);
        Route::middleware('super_admin')->group(function () {
            Route::get('settings/system', [AdminSettingsController::class, 'systemSettingsIndex']);
            Route::put('settings/system', [AdminSettingsController::class, 'systemSettingsUpdate']);
            Route::post('settings/system/test-email', [AdminSettingsController::class, 'testEmailConfiguration']);
        });
        Route::get('settings/activity-logs', [AdminSettingsController::class, 'activityLogs']);
    });

    // ══════════════════════════════════════════
    //  CUSTOMER PORTAL
    // ══════════════════════════════════════════
    Route::prefix('customer')->middleware('customer')->group(function () {

        // Dashboard (ringkasan untuk halaman Dashboard Customer Portal)
        Route::get('dashboard', [CustomerDashboardController::class, 'index']);
        Route::get('dashboard/notifications', [CustomerDashboardController::class, 'notifications']);

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

    // ── Vendor Portal API ────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'vendor'])->prefix('vendor')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::prefix('job-orders')->group(function () {
            Route::get('stats', [JobOrderController::class, 'stats']);
            Route::get('/', [JobOrderController::class, 'index']);
            Route::get('{shipment}', [JobOrderController::class, 'show']);
            Route::post('{shipment}/accept', [JobOrderController::class, 'accept']);
            Route::post('{shipment}/reject', [JobOrderController::class, 'reject']);
            Route::post('{shipment}/progress', [JobOrderController::class, 'submitProgress']);
            Route::post('{shipment}/submit-completion', [JobOrderController::class, 'submitCompletion']);
            Route::get('{shipment}/activities', [JobOrderController::class, 'activities']);
        });

        Route::prefix('documents')->group(function () {
            Route::get('stats', [DocumentController::class, 'stats']);
            Route::get('/', [DocumentController::class, 'index']);
            Route::get('{documentId}', [DocumentController::class, 'show']);
            Route::get('{documentId}/download', [DocumentController::class, 'download']);
        });

        Route::prefix('invoices')->group(function () {
            Route::get('stats', [VendorInvoiceController::class, 'stats']);
            Route::get('eligible-job-orders', [VendorInvoiceController::class, 'eligibleJobOrders']);
            Route::get('/', [VendorInvoiceController::class, 'index']);
            Route::post('/', [VendorInvoiceController::class, 'store']);
            Route::get('{invoice}', [VendorInvoiceController::class, 'show']);
            Route::post('{invoice}', [VendorInvoiceController::class, 'update']);
            Route::post('{invoice}/submit', [VendorInvoiceController::class, 'submit']);
            Route::get('{invoice}/download', [VendorInvoiceController::class, 'download']);
        });

        Route::prefix('payments')->group(function () {
            Route::get('stats', [VendorPaymentController::class, 'stats']);
            Route::get('/', [VendorPaymentController::class, 'index']);
            Route::get('{payment}', [VendorPaymentController::class, 'show']);
            Route::get('{payment}/receipt', [VendorPaymentController::class, 'receipt']);
        });

        Route::prefix('company')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\Vendor\CompanyController::class, 'show']);
            Route::put('/', [App\Http\Controllers\Api\Vendor\CompanyController::class, 'update']);
            Route::get('activities', [App\Http\Controllers\Api\Vendor\CompanyController::class, 'activities']);
        });

        Route::prefix('users')->group(function () {
            Route::get('stats', [App\Http\Controllers\Api\Vendor\UserController::class, 'stats']);
            Route::get('/', [App\Http\Controllers\Api\Vendor\UserController::class, 'index']);
            Route::post('/', [App\Http\Controllers\Api\Vendor\UserController::class, 'store']);
            Route::get('{user}', [App\Http\Controllers\Api\Vendor\UserController::class, 'show']);
            Route::put('{user}', [App\Http\Controllers\Api\Vendor\UserController::class, 'update']);
            Route::patch('{user}/role', [App\Http\Controllers\Api\Vendor\UserController::class, 'changeRole']);
            Route::patch('{user}/status', [App\Http\Controllers\Api\Vendor\UserController::class, 'changeStatus']);
            Route::post('{user}/reset-password', [App\Http\Controllers\Api\Vendor\UserController::class, 'resetPassword']);
            Route::get('{user}/activities', [App\Http\Controllers\Api\Vendor\UserController::class, 'activities']);
        });

        Route::prefix('my-profile')->group(function () {
            Route::get('/', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'show']);
            Route::put('/', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'update']);
            Route::post('change-password', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'changePassword']);
            Route::post('photo', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'uploadPhoto']);
            Route::delete('photo', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'deletePhoto']);
            Route::get('activities', [App\Http\Controllers\Api\Vendor\MyProfileController::class, 'activities']);
        });

        Route::prefix('master')->group(function () {
            Route::get('service-types', [App\Http\Controllers\Api\Vendor\MasterDataReadController::class, 'serviceTypes']);
            Route::get('transport-modes', [App\Http\Controllers\Api\Vendor\MasterDataReadController::class, 'transportModes']);
        });
    });
});
