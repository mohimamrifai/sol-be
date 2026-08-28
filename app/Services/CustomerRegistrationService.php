<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerRegistrationService
{
    public function __construct(
        private LocationCodeService $locationCode,
        private CustomerRegistrationMailer $mailer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated registration payload
     * @return array{company: Company, user: User}
     */
    public function register(array $data): array
    {
        $npwpDigits = preg_replace('/\D/', '', $data['npwp']) ?? '';

        $businessEntityType = $data['business_entity_type'] === 'Lainnya'
            ? 'Lainnya'
            : $data['business_entity_type'];

        return DB::transaction(function () use ($data, $npwpDigits, $businessEntityType) {
            $company = Company::create([
                'type' => Company::TYPE_CUSTOMER,
                'name' => $data['company_name'],
                'business_entity_type' => $businessEntityType,
                'business_entity_other' => $data['business_entity_other'] ?? null,
                'company_code' => strtoupper($data['company_code']),
                'npwp' => $npwpDigits,
                'address' => $data['address'],
                'city' => $data['city'],
                'province' => $data['province'],
                'country' => $data['country'],
                'district' => $data['district'],
                'postal_code' => $data['postal_code'],
                'phone' => $data['company_phone'],
                'email' => $data['company_email'],
                'website' => $data['website'] ?? null,
                'business_category' => $data['business_category'],
                'business_category_other' => $data['business_category_other'] ?? null,
                'monthly_shipment_estimate' => $data['monthly_shipment_estimate'],
                'contact_person' => $data['admin_name'],
                'status' => 'pending',
                'payment_type' => 'prepaid',
                'billing_type' => 'prepaid',
                'billing_cycle' => null,
                'payment_term' => null,
            ]);

            $user = User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['admin_phone'] ?? null,
                'user_type' => 'customer',
                'company_id' => $company->id,
                'status' => 'inactive',
            ]);

            $user->assignRole('company_admin');

            $headOffice = $this->createHeadOffice($company, $data);
            $user->locationAccess()->sync([$headOffice->id]);

            $this->mailer->sendPendingReview($company, $user);

            return [
                'company' => $company,
                'user' => $user->load('roles'),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createHeadOffice(Company $company, array $data): CustomerLocation
    {
        return CustomerLocation::create([
            'company_id' => $company->id,
            'code' => $this->locationCode->next($company->id, $company->name, LocationType::HeadOffice->value),
            'name' => $company->name,
            'type' => LocationType::HeadOffice->value,
            'status' => LocationStatus::Active->value,
            'country' => $data['country'],
            'province' => $data['province'],
            'city' => $data['city'],
            'district' => $data['district'],
            'postal_code' => $data['postal_code'],
            'address' => $data['address'],
            'phone' => $data['company_phone'],
            'pic_name' => $data['admin_name'],
            'pic_email' => $data['admin_email'],
            'pic_mobile' => $data['admin_phone'],
        ]);
    }
}
