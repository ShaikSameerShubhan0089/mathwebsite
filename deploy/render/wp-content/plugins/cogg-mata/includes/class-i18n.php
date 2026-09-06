<?php
/**
 * Interface language switching (Gaeilge / English).
 *
 * SRS §6.1 asks for "plain navigation labels in Irish (with English support as
 * agreed with COGG)", and §6.4 insists Irish is first-class content rather than
 * "translations bolted onto an English-first structure". So the source strings
 * throughout this plugin and theme are Irish, and this class maps them to
 * English on request — the opposite direction to a normal gettext catalogue,
 * and deliberately so: remove this file and the site is still correct Irish.
 *
 * Scope, which matters and is stated in the UI when English is active:
 *
 *   TRANSLATED   the interface — navigation, buttons, headings, facet labels,
 *                empty states, and the curriculum vocabulary that has official
 *                English names (class levels, strands).
 *
 *   NOT TRANSLATED   COGG's own resource and activity titles and descriptions.
 *                SRS §1.10 puts translation and content authorship with COGG,
 *                not the Vendor: "the Vendor is responsible for the platform
 *                correctly storing, rendering and searching Irish-language text
 *                — not for translation or content authorship." Machine-
 *                translating a curriculum resource title would be inventing
 *                content COGG has not approved (BR-02).
 *
 * Selection order: ?lang= in the URL, then the visitor's cookie, then Irish.
 * No account, no server-side profile — consistent with BR-01.
 *
 * @package cogg-mata
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class COGG_Mata_I18n {

	public const COOKIE = 'mata_lang';
	public const PARAM  = 'lang';

	private static ?string $lang = null;

	/** Irish source string => English. Keys must match the source exactly. */
	private const EN = array(

		/* -- navigation and chrome -- */
		'Uirlisí'                    => 'Toolkit',
		'Acmhainní'                  => 'Resources',
		'Gníomhaíochtaí'             => 'Activities',
		'Oscail gníomhaíocht'        => 'Open an activity',
		'Áis Mhatamaitice'           => 'Mathematics Hub',
		'Áis Mhatamaitice — COGG'    => 'Mathematics Hub — COGG',
		'Príomhroghchlár'            => 'Main menu',
		'Príomhnascleanúint'         => 'Main navigation',
		'Léim go dtí an príomhábhar' => 'Skip to main content',
		'Fógra príobháideachais'     => 'Privacy notice',
		'Riarachán'                  => 'Administration',
		'Acmhainní matamaitice do bhunscoileanna lán-Ghaeilge agus Gaeltachta'
			=> 'Mathematics resources for Irish-medium and Gaeltacht primary schools',

		/* -- homepage -- */
		'Matamaitic trí Ghaeilge, in aon áit amháin' => 'Mathematics through Irish, in one place',
		'Uirlisí idirghníomhacha, acmhainní iníoslódáilte agus gníomhaíochtaí digiteacha — gan cuntas, gan logáil isteach, gan sonraí daltaí a bhailiú riamh.'
			=> 'Interactive toolkits, downloadable resources and digital activities — no accounts, no logins, and no pupil data collected, ever.',
		'Oscail an uirlis'   => 'Open the toolkit',
		'Brabhsáil acmhainní' => 'Browse resources',
		'Cumraigh gníomhaíocht, sábháil í agus roinn í le do rang.'
			=> 'Configure an activity, save it and share it with your class.',
		'Pleananna ceachta agus tascanna ranga, scagtha de réir an churaclaim.'
			=> 'Lesson plans and classroom tasks, filtered by the curriculum.',
		'Gníomhaíochtaí digiteacha ó COGG — cuir in oiriúint do do rang iad.'
			=> 'COGG-authored digital activities — adapt them for your own class.',
		'Fuair tú comhad nó nasc ó do mhúinteoir? Oscail anseo é.'
			=> 'Got a file or link from your teacher? Open it here.',
		'Cosaint sonraí trí dhearadh.' => 'Data protection by design.',
		'Ní shábhálann an suíomh seo aon sonra faoi dhalta ar an bhfreastalaí. Cruthaítear an comhad gníomhaíochta i mbrabhsálaí an mhúinteora agus osclaítear i mbrabhsálaí an dalta é.'
			=> 'This site stores no pupil data on the server. The activity file is created in the teacher\'s browser and opened in the pupil\'s browser.',

		/* -- library and activity archives -- */
		'Gníomhaíochtaí digiteacha' => 'Digital activities',
		'Gach acmhainn scagtha de réir rangleibhéil, snáithe, aonad snáithe, topaice agus cineáil. Gan logáil isteach.'
			=> 'Every resource filtered by class level, strand, strand unit, topic and type. No login.',
		'Gníomhaíochtaí a d\'údaraigh COGG. Nuair a cheadaítear é, is féidir leat ceann a chur in oiriúint do do rang féin.'
			=> 'COGG-authored activities. Where it is supported you can adapt one for your own class.',
		'Cuardaigh'  => 'Search',
		'Scag'       => 'Filter',
		'Glan'       => 'Clear',
		'Íoslódáil'  => 'Download',
		'Féach'      => 'View',
		'Cuir in oiriúint' => 'Adapt',
		'Ní féidir í seo a chur in oiriúint' => 'This one cannot be adapted',
		'Scagairí'   => 'Filters',
		'Cuardaigh… (oibríonn sé le sínte fada nó gan iad)'
			=> 'Search… (works with or without fadas)',
		'Níor aimsíodh aon toradh' => 'No results found',
		'Bain scagaire amach nó déan an cuardach níos leithne.'
			=> 'Remove a filter or widen your search.',
		'Teastaíonn JavaScript chun an uirlis a úsáid. Tá na hacmhainní PDF ar fáil gan é.'
			=> 'JavaScript is needed for the toolkit. The PDF resources work without it.',
		'Ní theastaíonn cuntas chun an acmhainn seo a íoslódáil.'
			=> 'No account is needed to download this resource.',

		/* -- 404 -- */
		'Níor aimsíodh an leathanach sin' => 'Page not found',
		'B\'fhéidir gur bogadh é nó gur athraíodh an nasc.'
			=> 'It may have moved, or the link may have changed.',
		'Ar ais go dtí an baile' => 'Back to the home page',

		/* -- facet group labels (RFT §8.2.3) -- */
		'Rangleibhéal'      => 'Class level',
		'Snáithe'           => 'Strand',
		'Aonad snáithe'     => 'Strand unit',
		'Topaic'            => 'Topic',
		'Cineál acmhainne'  => 'Resource type',
		'Fócas foghlama'    => 'Learning focus',
		'Ailíniú curaclaim' => 'Curriculum alignment',
		'Formáid'           => 'Format',

		/* -- curriculum vocabulary with official English names -- */
		'Naíonáin Bheaga'   => 'Junior Infants',
		'Naíonáin Mhóra'    => 'Senior Infants',
		'Rang 1'            => '1st Class',
		'Rang 2'            => '2nd Class',
		'Rang 3'            => '3rd Class',
		'Rang 4'            => '4th Class',
		'Rang 5'            => '5th Class',
		'Rang 6'            => '6th Class',
		'Uimhir'            => 'Number',
		'Ailgéabar'         => 'Algebra',
		'Cruth agus Spás'   => 'Shape and Space',
		'Tomhas'            => 'Measures',
		'Sonraí agus Seans' => 'Data and Chance',
		/* strand units and topics — official curriculum English names */
		'Luach ionaid'              => 'Place value',
		'Suimiú agus dealú'         => 'Addition and subtraction',
		'Iolrú agus roinnt'         => 'Multiplication and division',
		'Codáin'                    => 'Fractions',
		'Patrúin'                   => 'Patterns',
		'Cruthanna 2T'              => '2-D shapes',
		'Cruthanna 3T'              => '3-D shapes',
		'Fad'                       => 'Length',
		'Achar'                     => 'Area',
		'Am'                        => 'Time',
		'Airgead'                   => 'Money',
		'Léaráidí'                  => 'Charts and graphs',
		'Táblaí'                    => 'Number facts',
		'Siméadracht'               => 'Symmetry',
		'Tomhas faid'               => 'Measuring length',
		'Sonraí'                    => 'Data',
		'Tuiscint'                  => 'Understanding',
		'Cumas'                     => 'Fluency',
		'Réiteach fadhbanna'        => 'Problem solving',
		'Cumarsáid mhatamaiticiúil' => 'Mathematical communication',
		'Curaclam Matamaitice na Bunscoile (athfhorbartha)'
			=> 'Primary Mathematics Curriculum (redeveloped)',

		'PDF'               => 'PDF',
		'Idirghníomhach'    => 'Interactive',
		'Treoir mhúinteora' => 'Teacher guidance',
		'Plean ceachta'     => 'Lesson plan',
		'Tasc ranga'        => 'Classroom task',
		'Bileog oibre'      => 'Worksheet',
		'Nasc seachtrach'   => 'External link',

		/* -- the switcher itself and its disclosure -- */
		'Gaeilge' => 'Gaeilge',
		'English' => 'English',
		'Nuair nach bhfuil leagan Béarla curtha ar fáil ag COGG, taispeántar an Ghaeilge.'
			=> 'Where COGG has not supplied an English version, the Irish is shown.',
	);

	public function __construct() {
		add_action( 'init', array( $this, 'capture_choice' ), 1 );
		add_filter( 'language_attributes', array( $this, 'html_lang' ) );
		add_filter( 'locale', array( $this, 'wp_locale' ), 20 );

		// Page titles, menu item labels and taxonomy term names all flow through
		// these. Anything absent from the dictionary — i.e. COGG's own content —
		// passes through untouched.
		add_filter( 'the_title', array( $this, 'post_title' ), 20, 2 );
		add_filter( 'single_post_title', array( $this, 'post_title' ), 20, 2 );

		// The browser <title> is assembled separately from the on-page <h1> and
		// does not pass through the_title, so it needs its own hook or the tab
		// says one language while the page says another.
		add_filter( 'document_title_parts', array( $this, 'document_title' ), 20 );
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'translate_menu_item' ), 20 );
		add_filter( 'term_name', array( $this, 'translate' ), 20 );

		// bloginfo() reads options directly and bypasses the filters above.
		// The tagline is descriptive so it translates; the site NAME is the
		// product's identity and deliberately stays Irish, the way a brand does.
		add_filter( 'option_blogdescription', array( $this, 'translate' ), 20 );
		add_filter( 'option_blogname', array( $this, 'translate' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'post_excerpt' ), 20, 2 );
		add_filter( 'single_term_title', array( $this, 'translate' ), 20 );
	}

	/** Persist a ?lang= choice in a cookie so it survives navigation. */
	public function capture_choice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display preference, not a state change
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$choice = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) );
		if ( ! in_array( $choice, array( 'ga', 'en' ), true ) ) {
			return;
		}

		self::$lang = $choice;

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$choice,
				array(
					'expires'  => time() + YEAR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'samesite' => 'Lax',
					'httponly' => false,
					'secure'   => is_ssl(),
				)
			);
		}
	}

	/** 'ga' or 'en'. Irish is the default; English is opt-in. */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$q = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) );
			if ( in_array( $q, array( 'ga', 'en' ), true ) ) {
				return self::$lang = $q;
			}
		}

		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$c = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( in_array( $c, array( 'ga', 'en' ), true ) ) {
				return self::$lang = $c;
			}
		}

		return self::$lang = 'ga';
	}

	public static function is_english(): bool {
		return 'en' === self::lang();
	}

	/**
	 * Translate one Irish source string. Unknown strings are returned as-is,
	 * which is what keeps COGG's content in Irish without a allow-list.
	 */
	public static function t( string $irish ): string {
		if ( ! self::is_english() ) {
			return $irish;
		}
		return self::EN[ $irish ] ?? $irish;
	}

	/**
	 * Title filter. A COGG-supplied English title wins; otherwise fall back to
	 * the interface dictionary (which covers page names like "Uirlisí"); other-
	 * wise the Irish stands, which is the correct default for untranslated
	 * COGG content.
	 */
	public function post_title( $title, $post = null ) {
		if ( ! self::is_english() || ! is_string( $title ) ) {
			return $title;
		}

		// the_title passes an ID; single_post_title passes a WP_Post. Normalise
		// rather than cast, or the object trips a conversion warning.
		$post_id = 0;
		if ( $post instanceof WP_Post ) {
			$post_id = $post->ID;
		} elseif ( is_numeric( $post ) ) {
			$post_id = (int) $post;
		}

		if ( $post_id > 0 ) {
			$en = COGG_Mata_English::title( $post_id );
			if ( '' !== $en ) {
				return $en;
			}
		}

		return self::t( $title );
	}

	/** Same rule for the short description shown on cards and detail pages. */
	public function post_excerpt( $excerpt, $post = null ) {
		if ( ! self::is_english() || ! is_string( $excerpt ) ) {
			return $excerpt;
		}
		$id = 0;
		if ( $post instanceof WP_Post ) {
			$id = $post->ID;
		} elseif ( is_numeric( $post ) ) {
			$id = (int) $post;
		}
		if ( $id ) {
			$en = COGG_Mata_English::excerpt( $id );
			if ( '' !== $en ) {
				return $en;
			}
		}
		return self::t( $excerpt );
	}

	/** Keep the browser tab in the same language as the page. */
	public function document_title( $parts ) {
		if ( ! self::is_english() || ! is_array( $parts ) ) {
			return $parts;
		}
		if ( isset( $parts['title'] ) && is_string( $parts['title'] ) ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post ) {
				$en = COGG_Mata_English::title( $queried->ID );
				if ( '' !== $en ) {
					$parts['title'] = $en;
					return $parts;
				}
			}
			$parts['title'] = self::t( $parts['title'] );
		}
		return $parts;
	}

	/** Filter callback form of t(). */
	public function translate( $text ) {
		return is_string( $text ) ? self::t( $text ) : $text;
	}

	public function translate_menu_item( $item ) {
		if ( isset( $item->title ) && is_string( $item->title ) ) {
			$item->title = self::t( $item->title );
		}
		return $item;
	}

	/** Keep <html lang> honest — screen readers and spell-checkers rely on it. */
	public function html_lang( string $output ): string {
		$lang = self::is_english() ? 'en-IE' : 'ga-IE';
		if ( preg_match( '/lang="[^"]*"/', $output ) ) {
			return (string) preg_replace( '/lang="[^"]*"/', 'lang="' . $lang . '"', $output );
		}
		return trim( $output . ' lang="' . $lang . '"' );
	}

	/** Nudge WordPress core's own strings across too, where a pack is present. */
	public function wp_locale( $locale ) {
		return self::is_english() ? 'en_GB' : $locale;
	}

	/**
	 * The URL that switches to the other language, preserving the current page.
	 *
	 * Deliberately HOST-RELATIVE. Building it with home_url() would bake in
	 * whatever WP_HOME says, which breaks the moment the site is reached by any
	 * other name — a tunnel, a staging alias, a LAN IP, or the .ie domain in
	 * front of the origin. A path keeps working under all of them.
	 */
	public static function switch_url(): string {
		$target = self::is_english() ? 'ga' : 'en';

		$request = isset( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '/';

		// Keep the path and query, discard anything else an odd proxy may send.
		$path  = wp_parse_url( $request, PHP_URL_PATH ) ?: '/';
		$query = wp_parse_url( $request, PHP_URL_QUERY );

		$relative = $path . ( $query ? '?' . $query : '' );

		return add_query_arg( self::PARAM, $target, $relative );
	}

	/** Label for the button: it names the language you would switch TO. */
	public static function switch_label(): string {
		return self::is_english() ? 'Gaeilge' : 'English';
	}

	public static function switch_code(): string {
		return self::is_english() ? 'GA' : 'EN';
	}
}

/**
 * Template helper. Short on purpose — it appears inline in markup a lot.
 */
function mata_t( string $irish ): string {
	return COGG_Mata_I18n::t( $irish );
}

/** Escaped for HTML output. */
function mata_e( string $irish ): void {
	echo esc_html( COGG_Mata_I18n::t( $irish ) );
}
