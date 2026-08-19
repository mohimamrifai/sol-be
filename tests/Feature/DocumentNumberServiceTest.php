<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DocumentNumberService;
use Database\Seeders\NumberingFormatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_monthly_fsd_booking_number(): void
    {
        $this->seed(NumberingFormatSeeder::class);
        $service = app(DocumentNumberService::class);

        $first = $service->generate('BK');
        $second = $service->generate('BK');

        $period = now()->format('Ym');
        $this->assertSame("BK-{$period}-00001", $first);
        $this->assertSame("BK-{$period}-00002", $second);
    }

    public function test_generates_vendor_job_order_number_with_jo_prefix(): void
    {
        $this->seed(NumberingFormatSeeder::class);
        $service = app(DocumentNumberService::class);

        $number = $service->generate('JO');
        $period = now()->format('Ym');

        $this->assertSame("JO-{$period}-00001", $number);
    }
}
