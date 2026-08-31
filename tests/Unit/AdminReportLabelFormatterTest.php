<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminReportLabelFormatter;
use PHPUnit\Framework\TestCase;

class AdminReportLabelFormatterTest extends TestCase
{
    public function test_formats_common_snake_case_values(): void
    {
        $this->assertSame('Port to Port', AdminReportLabelFormatter::shipmentCoverage('port_to_port'));
        $this->assertSame('Confirmed', AdminReportLabelFormatter::bookingStatus('approved'));
        $this->assertSame('Train Departed', AdminReportLabelFormatter::shipmentStatus('train_departed'));
        $this->assertSame('Partially Paid', AdminReportLabelFormatter::invoiceStatus('partially_paid'));
        $this->assertSame('Virtual Account', AdminReportLabelFormatter::paymentMethod('virtual_account'));
        $this->assertSame('Company', AdminReportLabelFormatter::containerOwnership('company'));
        $this->assertSame('In Transit', AdminReportLabelFormatter::containerStatus('in_transit'));
        $this->assertSame('Under Verification', AdminReportLabelFormatter::vendorInvoiceStatus('under_verification'));
        $this->assertSame('Waiting Approval', AdminReportLabelFormatter::vendorPaymentStatus('waiting_approval'));
    }
}
