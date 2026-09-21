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
        $this->assertArrayHasKey('BL1', $map);
        $this->assertArrayHasKey('WN1', $map);
        $this->assertArrayHasKey('WA3', $map);
    }

    public function test_get_branch_map_does_not_contain_out_of_area_codes()
    {
        $map = get_branch_map();
        $this->assertArrayNotHasKey('SW1', $map);
        $this->assertArrayNotHasKey('EC1', $map);
    }

    public function test_get_branch_map_maps_m1_to_city_centre_and_salford()
    {
        $map = get_branch_map();
        $this->assertSame('City Centre and Salford', $map['M1']);
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
        $this->assertArrayHasKey('M29', $map);
        $this->assertNull($map['M29']);
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

    public function test_outcode_m1_resolves_to_city_centre_and_salford()
    {
        $this->assertSame('City Centre and Salford', get_branch_for_outcode('M1'));
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

    public function test_outcode_ol10_resolves_to_rochdale()
    {
        $this->assertSame('Rochdale', get_branch_for_outcode('OL10'));
    }

    public function test_outcode_ol15_resolves_to_rochdale()
    {
        $this->assertSame('Rochdale', get_branch_for_outcode('OL15'));
    }

    public function test_outcode_sk1_resolves_to_stockport()
    {
        $this->assertSame('Stockport', get_branch_for_outcode('SK1'));
    }

    public function test_outcode_bl1_resolves_to_bolton()
    {
        $this->assertSame('Bolton', get_branch_for_outcode('BL1'));
    }

    public function test_outcode_wn1_resolves_to_wigan()
    {
        $this->assertSame('Wigan', get_branch_for_outcode('WN1'));
    }

    public function test_outcode_wa3_resolves_to_wigan()
    {
        $this->assertSame('Wigan', get_branch_for_outcode('WA3'));
    }

    public function test_outcode_wa13_resolves_to_null_no_branch()
    {
        $map = get_branch_map();
        $this->assertArrayHasKey('WA13', $map);
        $this->assertNull(get_branch_for_outcode('WA13'));
    }

    public function test_outcode_wa14_resolves_to_null_no_branch()
    {
        $map = get_branch_map();
        $this->assertArrayHasKey('WA14', $map);
        $this->assertNull(get_branch_for_outcode('WA14'));
    }

    public function test_branch_email_map_bolton_has_null_email()
    {
        $map = get_branch_email_map();
        $this->assertArrayHasKey('Bolton', $map);
        $this->assertNull($map['Bolton']);
    }

    public function test_branch_email_map_wigan_has_null_email()
    {
        $map = get_branch_email_map();
        $this->assertArrayHasKey('Wigan', $map);
        $this->assertNull($map['Wigan']);
    }

    public function test_outcode_m29_resolves_to_null_unassigned()
    {
        $this->assertNull(get_branch_for_outcode('M29'));
    }

    public function test_unknown_outcode_resolves_to_null()
    {
        $this->assertNull(get_branch_for_outcode('SW1'));
    }

    // City Centre and Salford, the branch split out of the old "South and Central".
    // Outcodes and branch names come from GMTU's branch postcode breakdown sheets.

    /**
     * @dataProvider cityCentreAndSalfordOutcodeProvider
     */
    public function test_outcode_resolves_to_city_centre_and_salford($outcode)
    {
        $this->assertSame('City Centre and Salford', get_branch_for_outcode($outcode));
    }

    public function cityCentreAndSalfordOutcodeProvider()
    {
        return [
            'M1 Piccadilly, Market Street, Gay Village' => ['M1'],
            'M2 Deansgate' => ['M2'],
            'M3 City Centre, Castlefield, Blackfriars, Greengate' => ['M3'],
            'M17 Trafford Park' => ['M17'],
            'M27 Swinton, Clifton, Pendlebury' => ['M27'],
            'M28 Worsley, Walkden, Boothstown' => ['M28'],
            'M30 Eccles, Monton, Peel Green, Winton' => ['M30'],
            'M38 Little Hulton' => ['M38'],
            'M5 Ordsall, Seedley, Weaste, University' => ['M5'],
            'M6 Pendleton, Langworthy, Charlestown' => ['M6'],
            'M7 Higher and Lower Broughton, Kersal' => ['M7'],
            'M44 Irlam, Cadishead' => ['M44'],
            'M50 Salford Quays, MediaCityUK' => ['M50'],
            'M4 Arndale, Ancoats, Northern Quarter, Shudehill' => ['M4'],
        ];
    }

    /**
     * South Manchester keeps the outcodes GMTU's sheet leaves with it.
     *
     * @dataProvider southManchesterOutcodeProvider
     */
    public function test_outcode_still_resolves_to_south_manchester($outcode)
    {
        $this->assertSame('South Manchester', get_branch_for_outcode($outcode));
    }

    public function southManchesterOutcodeProvider()
    {
        return [
            'M20 Didsbury, Withington' => ['M20'],
            'M21 Chorlton-cum-Hardy' => ['M21'],
            'M22 Wythenshawe, Northenden' => ['M22'],
            'M23 Baguley, Brooklands' => ['M23'],
        ];
    }

    public function test_branch_email_map_maps_city_centre_and_salford()
    {
        $map = get_branch_email_map();
        $this->assertSame('citycentre@tenantsunion.org.uk', $map['City Centre and Salford']);
    }

    // Bury, added by GMTU's revised sheet.

    /**
     * @dataProvider buryOutcodeProvider
     */
    public function test_outcode_resolves_to_bury($outcode)
    {
        $this->assertSame('Bury', get_branch_for_outcode($outcode));
    }

    public function buryOutcodeProvider()
    {
        return [
            'M25 Prestwich, Sedgley Park, Simister' => ['M25'],
            'M26 Radcliffe, Stoneclough' => ['M26'],
            'M45 Whitefield, Besses o\' th\' Barn' => ['M45'],
            'BL8 Bury centre, Tottington, Ramsbottom' => ['BL8'],
            'BL9 Bury centre, Summerseat, Walmersley' => ['BL9'],
        ];
    }

    public function test_branch_email_map_bury_has_null_email()
    {
        $map = get_branch_email_map();
        $this->assertArrayHasKey('Bury', $map);
        $this->assertNull($map['Bury']);
    }

}
