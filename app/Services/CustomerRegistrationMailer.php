<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CustomerRegistrationReceivedMail;
use App\Mail\CustomerRegistrationRejectedMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerRegistrationMailer
{
    public function sendPendingReview(Company $company, User $adminUser): void
    {
        $this->sendMail(new CustomerRegistrationReceivedMail($company, $adminUser), $adminUser->email);
    }

    public function sendRejected(Company $company, User $adminUser, string $reason): void
    {
        $this->sendMail(new CustomerRegistrationRejectedMail($company, $adminUser, $reason), $adminUser->email);
    }

    private function sendMail(object $mailable, string $to): void
    {
        if ($to === '') {
            return;
        }

        try {
            Mail::to($to)->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Customer registration email failed.', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
