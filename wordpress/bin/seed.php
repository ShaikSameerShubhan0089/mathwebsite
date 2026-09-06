<?php
/**
 * Sample content, run through WP-CLI (`wp eval-file`).
 *
 * The last item is deliberately incomplete: it has no topic and no format, so
 * it CANNOT be published. That is not an oversight — it is the demonstration.
 * Open it in wp-admin, press Publish, and the metadata gate (BR-03) refuses and
 * names the missing fields. A tender demo should show the control working, not
 * just assert that it exists.
 *
 * @package cogg-mata
 */

// NOTE: no declare(strict_types=1) here on purpose — WP-CLI's eval-file runs
// this through eval(), where a strict_types declaration is a fatal error.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$resources = array(
	array( 'Luach ionaid go 99 — plean ceachta', 'Ceacht iomlán le gníomhaíochtaí praiticiúla ar aonaid agus deicheanna, le hábhair inphriontáilte.', 'Rang 1', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Plean ceachta', 'PDF' ),
	array( 'Codáin aonaid — tascanna ranga', 'Sraith tascanna chun codáin aonaid a aithint agus a chur i gcomparáid le chéile.', 'Rang 3', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'PDF' ),
	array( 'Táblaí iolraithe 2, 5 agus 10', 'Bileoga oibre agus cluichí gearra chun na táblaí bunúsacha a dhaingniú.', 'Rang 2', 'Uimhir', 'Iolrú agus roinnt', 'Táblaí', 'Bileog oibre', 'PDF' ),
	array( 'Siméadracht i gcruthanna 2T', 'Gníomhaíochtaí fillte agus scátháin chun aiseanna siméadrachta a fháil amach.', 'Rang 4', 'Cruth agus Spás', 'Cruthanna 2T', 'Siméadracht', 'Plean ceachta', 'PDF' ),
	array( 'Tomhas faid le haonaid neamhchaighdeánacha', 'Ceacht tosaigh ar thomhas ag baint úsáide as spanla, coiscéimeanna agus slata.', 'Naíonáin Mhóra', 'Tomhas', 'Fad', 'Tomhas faid', 'Plean ceachta', 'PDF' ),
	array( 'An t-am — an leathuair a léamh', 'Bileoga oibre le clog analógach agus digiteach, le treoir don mhúinteoir.', 'Rang 2', 'Tomhas', 'Am', 'Am', 'Bileog oibre', 'PDF' ),
	array( 'Airgead — sóinseáil go €2', 'Tascanna praiticiúla siopadóireachta le buntáiste don obair bheirte.', 'Rang 3', 'Tomhas', 'Airgead', 'Airgead', 'Tasc ranga', 'PDF' ),
	array( 'Patrúin agus seichimh a leanúint', 'Réamhobair ailgéabair: patrúin datha, cruth agus uimhreach a aithint agus a leanúint.', 'Rang 1', 'Ailgéabar', 'Patrúin', 'Patrúin', 'Plean ceachta', 'PDF' ),
	array( 'Léaráidí barra a léamh agus a chruthú', 'Sonraí a bhailiú ón rang agus léaráid bharra a tharraingt le chéile.', 'Rang 4', 'Sonraí agus Seans', 'Léaráidí', 'Sonraí', 'Tasc ranga', 'PDF' ),
	array( 'Cruthanna 3T a aithint sa seomra ranga', 'Sealgaireacht chruthanna le liosta seiceála inphriontáilte.', 'Naíonáin Bheaga', 'Cruth agus Spás', 'Cruthanna 3T', 'Siméadracht', 'Tasc ranga', 'PDF' ),
	array( 'Achar dronuilleoga a ríomh', 'Ó chomhaireamh cearnóg go dtí an fhoirmle — céimnithe thar thrí cheacht.', 'Rang 5', 'Tomhas', 'Achar', 'Tomhas faid', 'Plean ceachta', 'PDF' ),
	array( 'Codáin choibhéiseacha — bileoga oibre', 'Trí leibhéal deacrachta chun freastal ar an rang ar fad.', 'Rang 5', 'Uimhir', 'Codáin', 'Codáin', 'Bileog oibre', 'PDF' ),
	array( 'Suimiú le hathghrúpáil go 999', 'Modheolaíocht chéim ar chéim le hábhair choincréiteacha.', 'Rang 3', 'Uimhir', 'Suimiú agus dealú', 'Luach ionaid', 'Plean ceachta', 'PDF' ),
	array( 'Treoir mhúinteora — teanga na matamaitice', 'Nótaí don mhúinteoir ar théarmaíocht chomhsheasmhach Ghaeilge ar fud na scoile.', 'Rang 5', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Treoir mhúinteora', 'Treoir mhúinteora' ),
);

$activities = array(
	array( 'Líne uimhreach go 20', 'Cuir uimhreacha in iúl ar líne uimhreach. Oiriúnach do rang iomlán ar an gclár bán.', 'Rang 1', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Tasc ranga', 'Idirghníomhach', 'linear', array( 'min' => 0, 'max' => 20, 'step' => 1, 'marks' => 4, 'labels' => true ), true ),
	array( 'Luach ionaid — trí cholún', 'Tóg uimhreacha le céadta, deicheanna agus aonaid.', 'Rang 3', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Tasc ranga', 'Idirghníomhach', 'pv', array( 'cols' => 3, 'count' => 6 ), true ),
	array( 'Codáin a dhathú', 'Dathaigh an codán a iarrtar ort — barra nó ciorcal.', 'Rang 3', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'frac', array( 'shape' => 'barra', 'dmin' => 2, 'dmax' => 6, 'count' => 6, 'mode' => 'dath' ), true ),
	array( 'Táblaí 2, 5, 10', 'Cleachtadh tapa ar na táblaí bunúsacha.', 'Rang 2', 'Uimhir', 'Iolrú agus roinnt', 'Táblaí', 'Tasc ranga', 'Idirghníomhach', 'tab', array( 'tables' => array( 2, 5, 10 ), 'op' => '×', 'count' => 10 ), true ),
	array( 'Codáin a ainmniú (measúnú)', 'Leagan measúnaithe — tá na socruithe faoi ghlas d\'fhonn comhionannas a chinntiú.', 'Rang 4', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'frac', array( 'shape' => 'ciorcal', 'dmin' => 3, 'dmax' => 8, 'count' => 8, 'mode' => 'ainm' ), false ),
	array( 'Líne uimhreach — deichiúlacha', 'Líne uimhreach ó 0 go 5 i gcéimeanna 0.5.', 'Rang 5', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'linear', array( 'min' => 0, 'max' => 5, 'step' => 0.5, 'marks' => 5, 'labels' => true ), true ),
);

$tax_order = array( 'mata_class_level', 'mata_strand', 'mata_strand_unit', 'mata_topic', 'mata_resource_type', 'mata_format' );

$author_id = ( $u = get_user_by( 'login', 'aoife' ) ) ? $u->ID : 1;
$made      = 0;
$skipped   = 0;

foreach ( $resources as $row ) {
	if ( get_page_by_title( $row[0], OBJECT, COGG_Mata_CPT::POST_RESOURCE ) ) {
		$skipped++;
		continue;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => COGG_Mata_CPT::POST_RESOURCE,
			'post_title'   => $row[0],
			'post_excerpt' => $row[1],
			'post_content' => $row[1],
			'post_status'  => 'draft',
			'post_author'  => $author_id,
		)
	);
	if ( is_wp_error( $id ) ) {
		continue;
	}
	foreach ( $tax_order as $i => $taxonomy ) {
		wp_set_object_terms( $id, $row[ $i + 2 ], $taxonomy );
	}
	// Publish only now that metadata is attached — the gate would refuse otherwise.
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	$made++;
}

foreach ( $activities as $row ) {
	if ( get_page_by_title( $row[0], OBJECT, COGG_Mata_CPT::POST_ACTIVITY ) ) {
		$skipped++;
		continue;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => COGG_Mata_CPT::POST_ACTIVITY,
			'post_title'   => $row[0],
			'post_excerpt' => $row[1],
			'post_content' => $row[1],
			'post_status'  => 'draft',
			'post_author'  => ( $d = get_user_by( 'login', 'dara' ) ) ? $d->ID : $author_id,
		)
	);
	if ( is_wp_error( $id ) ) {
		continue;
	}
	foreach ( $tax_order as $i => $taxonomy ) {
		wp_set_object_terms( $id, $row[ $i + 2 ], $taxonomy );
	}
	update_post_meta( $id, '_mata_kit', $row[8] );
	update_post_meta( $id, '_mata_cfg', wp_json_encode( $row[9] ) );
	update_post_meta( $id, '_mata_adaptable', $row[10] ? 1 : 0 );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
	$made++;
}

/* The deliberate failure case — see the file header. */
$incomplete_title = 'Dul chun cinn i dtáblaí — dréacht';
if ( ! get_page_by_title( $incomplete_title, OBJECT, COGG_Mata_CPT::POST_RESOURCE ) ) {
	$id = wp_insert_post(
		array(
			'post_type'    => COGG_Mata_CPT::POST_RESOURCE,
			'post_title'   => $incomplete_title,
			'post_excerpt' => 'Doiciméad dréachta — níl an mheiteashonraí iomlán, mar sin ní féidir é a fhoilsiú.',
			'post_status'  => 'draft',
			'post_author'  => $author_id,
		)
	);
	if ( ! is_wp_error( $id ) ) {
		wp_set_object_terms( $id, 'Rang 4', 'mata_class_level' );
		wp_set_object_terms( $id, 'Uimhir', 'mata_strand' );
		wp_set_object_terms( $id, 'Iolrú agus roinnt', 'mata_strand_unit' );
		// topic, resource_type and format left empty on purpose.
		$made++;
	}
}

WP_CLI::success( sprintf( 'Cruthaíodh %d mír, scipeáladh %d a bhí ann cheana.', $made, $skipped ) );
WP_CLI::log( 'Tá "' . $incomplete_title . '" fágtha mar dhréacht d\'aon ghnó — bain triail as é a fhoilsiú chun an geata meiteashonraí a fheiceáil.' );
