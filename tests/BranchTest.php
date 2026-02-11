<?php

namespace CommonKnowledge\JoinBlock\Organisation\GMTU\Tests;

use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_branch_map;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_branch_email_map;
use function CommonKnowledge\JoinBlock\Organisation\GMTU\get_branch_for_outcode;

class BranchTest extends TestCase
{
    public function test_get_branch_map_returns_array()
    {
        $map = get_branch_map();
        $this->assertIsArray($map);
        $this->assertNotEmpty($map);
    }

    public function test_get_branch_map_contains_expected_outcodes()
    {
        $map = get_branch_map();
        $this->assertArrayHasKey('M1', $map);
        $this->assertArrayHasKey('M8', $map);
        $this->assertArrayHasKey('M14', $map);
        $this->assertArrayHasKey('SK1', $map);
        $this->assertArrayHasKey('OL11', $map);
    }

    public function test_get_branch_map_does_not_contain_out_of_area_codes()
    {
        $map = get_branch_map();
        $this->assertArrayNotHasKey('SW1', $map);
        $this->assertArrayNotHasKey('EC1', $map);
    }

    public function test_get_branch_map_maps_m1_to_south_manchester()
    {
        $map = get_branch_map();
        $this->assertSame('South Manchester', $map['M1']);
    }

    public function test_get_branch_map_maps_m8_to_harpurhey()
    {
        $map = get_branch_map();
        $this->assertSame('Harpurhey', $map['M8']);
    }

    public function test_get_branch_map_maps_sk1_to_stockport()
    {
        $map = get_branch_map();
        $this->assertSame('Stockport', $map['SK1']);
    }

    public function test_get_branch_map_maps_ol11_to_rochdale()
    {
        $map = get_branch_map();
        $this->assertSame('Rochdale', $map['OL11']);
    }

    public function test_get_branch_map_contains_null_for_unassigned_outcodes()
    {
        $map = get_branch_map();
        $this->assertArrayHasKey('M5', $map);
        $this->assertNull($map['M5']);
    }

    public function test_get_branch_email_map_returns_array()
    {
        $map = get_branch_email_map();
        $this->assertIsArray($map);
        $this->assertNotEmpty($map);
    }

    public function test_get_branch_email_map_maps_south_manchester()
    {
        $map = get_branch_email_map();
        $this->assertSame('south.mcr@tenantsunion.org.uk', $map['South Manchester']);
    }

    public function test_get_branch_email_map_maps_hulme()
    {
        $map = get_branch_email_map();
        $this->assertSame('hulme@tenantsunion.org.uk', $map['Hulme']);
    }

    public function test_get_branch_email_map_stockport_has_null_email()
    {
        $map = get_branch_email_map();
        $this->assertArrayHasKey('Stockport', $map);
        $this->assertNull($map['Stockport']);
    }

    // get_branch_for_outcode — one test per branch

    public function test_outcode_m1_resolves_to_south_manchester()
    {
        $this->assertSame('South Manchester', get_branch_for_outcode('M1'));
    }

    public function test_outcode_m8_resolves_to_harpurhey()
    {
        $this->assertSame('Harpurhey', get_branch_for_outcode('M8'));
    }

    public function test_outcode_m12_resolves_to_leve_longsight()
    {
        $this->assertSame('Leve-Longsight', get_branch_for_outcode('M12'));
    }

    public function test_outcode_m14_resolves_to_moss_side()
    {
        $this->assertSame('Moss Side', get_branch_for_outcode('M14'));
    }

    public function test_outcode_m15_resolves_to_hulme()
    {
        $this->assertSame('Hulme', get_branch_for_outcode('M15'));
    }

    public function test_outcode_m24_resolves_to_middleton()
    {
        $this->assertSame('Middleton', get_branch_for_outcode('M24'));
    }

    public function test_outcode_ol11_resolves_to_rochdale()
    {
        $this->assertSame('Rochdale', get_branch_for_outcode('OL11'));
    }

    public function test_outcode_sk1_resolves_to_stockport()
    {
        $this->assertSame('Stockport', get_branch_for_outcode('SK1'));
    }

    public function test_outcode_m5_resolves_to_null_unassigned()
    {
        $this->assertNull(get_branch_for_outcode('M5'));
    }

    public function test_unknown_outcode_resolves_to_null()
    {
        $this->assertNull(get_branch_for_outcode('SW1'));
    }
}
