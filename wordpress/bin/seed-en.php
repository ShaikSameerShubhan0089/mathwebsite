<?php
/**
 * English renderings for the demo content, run through WP-CLI (`wp eval-file`).
 *
 * In production these fields start empty and COGG fills them when publishing —
 * an English title is COGG-authored content and goes through the same editorial
 * approval as the Irish (BR-02, SRS §1.10). They are pre-filled here so an
 * evaluator opening the demo sees a complete English site rather than a
 * half-translated one.
 *
 * Keyed on the Irish title, so re-running after a content edit is safe and
 * nothing is overwritten that COGG has since changed by hand.
 *
 * NOTE: no declare(strict_types=1) — WP-CLI's eval-file would fatal on it.
 *
 * @package cogg-mata
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/** Irish title => [ English title, English description ]. */
$english = array(

	/* ---- resources ---- */
	'Luach ionaid go 99 — plean ceachta' => array(
		'Place value to 99 — lesson plan',
		'A complete lesson with hands-on activities on units and tens, including printable materials.',
	),
	'Codáin aonaid — tascanna ranga' => array(
		'Unit fractions — classroom tasks',
		'A series of tasks for recognising unit fractions and comparing them with one another.',
	),
	'Táblaí iolraithe 2, 5 agus 10' => array(
		'Multiplication tables 2, 5 and 10',
		'Worksheets and short games for consolidating the basic tables.',
	),
	'Siméadracht i gcruthanna 2T' => array(
		'Symmetry in 2-D shapes',
		'Folding and mirror activities for discovering axes of symmetry.',
	),
	'Tomhas faid le haonaid neamhchaighdeánacha' => array(
		'Measuring length with non-standard units',
		'An introductory measuring lesson using handspans, paces and rods.',
	),
	'An t-am — an leathuair a léamh' => array(
		'Telling the time — reading the half hour',
		'Worksheets with analogue and digital clocks, with guidance for the teacher.',
	),
	'Airgead — sóinseáil go €2' => array(
		'Money — giving change up to €2',
		'Practical shopping tasks, well suited to work in pairs.',
	),
	'Patrúin agus seichimh a leanúint' => array(
		'Continuing patterns and sequences',
		'Pre-algebra work: recognising and continuing colour, shape and number patterns.',
	),
	'Léaráidí barra a léamh agus a chruthú' => array(
		'Reading and creating bar charts',
		'Collecting data from the class and drawing a bar chart together.',
	),
	'Cruthanna 3T a aithint sa seomra ranga' => array(
		'Recognising 3-D shapes in the classroom',
		'A shape hunt with a printable checklist.',
	),
	'Treoir mhúinteora — teanga na matamaitice' => array(
		'Teacher guidance — the language of mathematics',
		'Notes for the teacher on consistent Irish terminology across the school.',
	),
	'Achar dronuilleoga a ríomh' => array(
		'Calculating the area of rectangles',
		'From counting squares to the formula — staged across three lessons.',
	),
	'Codáin choibhéiseacha — bileoga oibre' => array(
		'Equivalent fractions — worksheets',
		'Three levels of difficulty, to cater for the whole class.',
	),
	'Suimiú le hathghrúpáil go 999' => array(
		'Addition with regrouping to 999',
		'A step-by-step methodology using concrete materials.',
	),
	'Dul chun cinn i dtáblaí — dréacht' => array(
		'Progression in number facts — draft',
		'A draft document — its metadata is incomplete, so it cannot be published.',
	),

	/* ---- digital activities ---- */
	'Líne uimhreach go 20' => array(
		'Number line to 20',
		'Identify numbers on a number line. Suits a whole class on the interactive whiteboard.',
	),
	'Luach ionaid — trí cholún' => array(
		'Place value — three columns',
		'Build numbers using hundreds, tens and units.',
	),
	'Codáin a dhathú' => array(
		'Shading fractions',
		'Shade the fraction you are asked for — on a bar or a circle.',
	),
	'Táblaí 2, 5, 10' => array(
		'Tables 2, 5 and 10',
		'Quick practice on the basic tables.',
	),
	'Codáin a ainmniú (measúnú)' => array(
		'Naming fractions (assessment)',
		'An assessment version — the settings are locked so every pupil gets the same task.',
	),
	'Líne uimhreach — deichiúlacha' => array(
		'Number line — decimals',
		'A number line from 0 to 5 in steps of 0.5.',
	),
);

$items = get_posts(
	array(
		'post_type'      => array( COGG_Mata_CPT::POST_RESOURCE, COGG_Mata_CPT::POST_ACTIVITY ),
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => -1,
	)
);

$filled   = 0;
$skipped  = 0;
$untitled = array();

foreach ( $items as $item ) {
	$irish = $item->post_title;

	if ( ! isset( $english[ $irish ] ) ) {
		$untitled[] = $irish;
		continue;
	}

	// Never clobber something a COGG editor has already written.
	if ( '' !== COGG_Mata_English::title( $item->ID ) ) {
		$skipped++;
		continue;
	}

	update_post_meta( $item->ID, COGG_Mata_English::META_TITLE, $english[ $irish ][0] );
	update_post_meta( $item->ID, COGG_Mata_English::META_EXCERPT, $english[ $irish ][1] );
	$filled++;
}

WP_CLI::success( sprintf( 'English filled for %d items, %d already had one.', $filled, $skipped ) );

if ( ! empty( $untitled ) ) {
	WP_CLI::log( 'No English supplied for (these will show Irish to English visitors):' );
	foreach ( $untitled as $t ) {
		WP_CLI::log( '  - ' . $t );
	}
}
