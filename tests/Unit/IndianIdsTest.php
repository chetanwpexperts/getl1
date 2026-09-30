<?php

namespace Tests\Unit;

use App\Support\IndianIds;
use PHPUnit\Framework\TestCase;

class IndianIdsTest extends TestCase
{
    public function test_valid_gstins_pass_checksum(): void
    {
        $this->assertTrue(IndianIds::isValidGstin('27AAPFU0939F1ZV'));
        $this->assertTrue(IndianIds::isValidGstin('29AAGCB7383J1Z4'));
        $this->assertTrue(IndianIds::isValidGstin('03aaacr5055k1zh')); // lower-case accepted
    }

    public function test_bad_gstins_fail(): void
    {
        $this->assertFalse(IndianIds::isValidGstin('27AAPFU0939F1ZW')); // wrong check char
        $this->assertFalse(IndianIds::isValidGstin('99AAPFU0939F1ZV')); // no state 99
        $this->assertFalse(IndianIds::isValidGstin('27AAPFU0939F1Z'));  // too short
        $this->assertFalse(IndianIds::isValidGstin(''));
        $this->assertFalse(IndianIds::isValidGstin(null));
    }

    public function test_pan_and_udyam(): void
    {
        $this->assertTrue(IndianIds::isValidPan('AAPFU0939F'));
        $this->assertFalse(IndianIds::isValidPan('ABCDE1234F')); // 4th char D is not a holder type
        $this->assertTrue(IndianIds::isValidUdyam('UDYAM-PB-01-0012345'));
        $this->assertFalse(IndianIds::isValidUdyam('UDYAM-PB-1-0012345'));
        $this->assertSame('AAPFU0939F', IndianIds::panFromGstin('27AAPFU0939F1ZV'));
    }
}
