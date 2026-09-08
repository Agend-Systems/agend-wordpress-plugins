<?php
$mk = function ($name, $street, $city, $state, $pc, $cat, $owner, $asset, $glar) {
	return array(
		'id' => md5($name), 'slug' => sanitize_title($name), 'name' => $name, 'updated_at' => '2025-03-12T00:00:00+00:00',
		'primary_category' => array('id' => 'c1', 'name' => $cat),
		'primary_location' => array('address_line_1' => $street, 'city' => $city, 'state' => $state, 'postcode' => $pc),
		'custom_fields' => array(
			array('key' => 'owners', 'label' => 'Owners', 'type' => 'text', 'value' => $owner),
			array('key' => 'asset_owners', 'label' => 'Asset Owners', 'type' => 'text', 'value' => $asset),
			array('key' => 'total_centre_glar_sqm', 'label' => 'Total Centre GLAR (sqm)', 'type' => 'number', 'value' => $glar),
		),
	);
};
$records = array(
	$mk('Westfield Bondi Junction', '500 Oxford Street', 'Bondi Junction', 'NSW', '2022', 'Super Regional', 'Scentre Group', 'Scentre Group', '95,617'),
	$mk('Westfield Sydney', '188 Pitt Street', 'Sydney', 'NSW', '2000', 'City Centre', 'Scentre Group', 'Scentre Group', '56,900'),
	$mk('Top Ryde City', '109 Blaxland Road', 'Ryde', 'NSW', '2112', 'Regional', 'CBRE Investment Mgmt', 'JLL', '54,200'),
	$mk('Chatswood Chase', '345 Victoria Avenue', 'Chatswood', 'NSW', '2067', 'Regional', 'Vicinity Centres', 'Vicinity Centres', '48,900'),
	$mk('Macquarie Centre', '1 Herring Road', 'North Ryde', 'NSW', '2113', 'Super Regional', 'AMP Capital', 'JLL', '111,000'),
	$mk('Bondi Beach Plaza', '17 Hall Street', 'Bondi Beach', 'NSW', '2026', 'Sub Regional', 'Fortius Funds Mgmt', '', ''),
);
$cards = agend_apps_records_render_cards('listing', (int) $args[0], $records, array('with_css' => false, 'host_page_id' => (int) $args[1]));
foreach ($cards as $c) { echo $c['html']; }
