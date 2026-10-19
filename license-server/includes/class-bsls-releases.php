<?php
/**
 * Plugin-Releases für Updates der Lizenznehmer.
 *
 * Quellen:
 *  1. GitHub (empfohlen): Das neueste Release eines (privaten) Repositorys wird
 *     mit einem NUR auf diesem Server gespeicherten Token gelesen. Die ZIP-Assets
 *     (z. B. blocksocial-woocommerce-sync-3.2.0.zip) werden beim ersten Abruf
 *     geladen, lokal zwischengespeichert und nur an lizenzierte Shops ausgeliefert.
 *  2. Manueller Upload einer Plugin-ZIP im Admin (Fallback).
 *
 * @package BlockSocial_License_Server
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Release-Verwaltung.
 */
class BSLS_Releases {

	const OPT          = 'bsls_releases';
	const GITHUB_OPT   = 'bsls_github';
	const GITHUB_CACHE = 'bsls_github_release';

	/**
	 * Geschütztes Ablageverzeichnis.
	 *
	 * @return string
	 */
	public static function dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . 'bsls-releases';
	}

	/**
	 * Legt das Verzeichnis an und sperrt den direkten Web-Zugriff.
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
	}

	/**
	 * GitHub-Einstellungen.
	 *
	 * @return array { repo, token }
	 */
	public static function github() {
		return wp_parse_args( (array) get_option( self::GITHUB_OPT, array() ), array( 'repo' => '', 'token' => '' ) );
	}

	/**
	 * Alle bekannten Releases (manuell + GitHub), je Plugin-Slug das neueste.
	 *
	 * @return array slug => release
	 */
	public static function all() {
		$manual = (array) get_option( self::OPT, array() );
		$gh     = self::github_releases();
		foreach ( $gh as $slug => $rel ) {
			if ( ! isset( $manual[ $slug ] ) || version_compare( $rel['version'], $manual[ $slug ]['version'], '>' ) ) {
				$manual[ $slug ] = $rel;
			}
		}
		ksort( $manual );
		return $manual;
	}

	/**
	 * Release eines Plugins.
	 *
	 * @param string $slug Plugin-Slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	// -------------------------------------------------------------------------
	// Manueller Upload
	// -------------------------------------------------------------------------

	/**
	 * Übernimmt eine Plugin-ZIP als Release (liest Kopfdaten und Changelog).
	 *
	 * @param string $path Pfad zur ZIP.
	 * @return array|WP_Error Release.
	 */
	public static function add_from_file( $path ) {
		$meta = self::read_zip( $path );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		self::ensure_dir();
		$target = self::dir() . '/' . $meta['slug'] . '-' . $meta['version'] . '.zip';
		if ( ! @copy( $path, $target ) ) { // phpcs:ignore
			return new WP_Error( 'bsls_copy', __( 'ZIP konnte nicht gespeichert werden.', 'blocksocial-license-server' ) );
		}
		$meta['file']   = basename( $target );
		$meta['source'] = 'upload';
		$all                  = (array) get_option( self::OPT, array() );
		$all[ $meta['slug'] ] = $meta;
		update_option( self::OPT, $all, false );
		return $meta;
	}

	/**
	 * Liest Slug, Version, Anforderungen und Changelog aus einer Plugin-ZIP.
	 *
	 * @param string $path ZIP.
	 * @return array|WP_Error
	 */
	public static function read_zip( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'bsls_zip', __( 'PHP-Erweiterung „zip" fehlt.', 'blocksocial-license-server' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'bsls_zip', __( 'Keine gültige ZIP-Datei.', 'blocksocial-license-server' ) );
		}
		$meta = null;
		$readme = '';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name  = $zip->getNameIndex( $i );
			$parts = explode( '/', $name );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			if ( 'readme.txt' === $parts[1] ) {
				$readme = (string) $zip->getFromIndex( $i );
				continue;
			}
			if ( '.php' !== substr( $parts[1], -4 ) || $meta ) {
				continue;
			}
			$head = substr( (string) $zip->getFromIndex( $i ), 0, 8192 );
			if ( ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $m ) ) {
				continue;
			}
			$meta = array(
				'slug'         => $parts[0],
				'name'         => trim( $m[1] ),
				'version'      => self::header( $head, 'Version' ),
				'requires'     => self::header( $head, 'Requires at least' ),
				'tested'       => self::header( $head, 'Tested up to' ),
				'requires_php' => self::header( $head, 'Requires PHP' ),
			);
		}
		$zip->close();
		if ( ! $meta || '' === $meta['version'] ) {
			return new WP_Error( 'bsls_zip', __( 'In der ZIP wurde kein Plugin mit Versionsangabe gefunden.', 'blocksocial-license-server' ) );
		}
		if ( '' === $meta['tested'] && preg_match( '/^Tested up to:\s*(.+)$/mi', $readme, $m ) ) {
			$meta['tested'] = trim( $m[1] );
		}
		$meta['changelog'] = self::changelog_from_readme( $readme );
		$meta['date']      = gmdate( 'Y-m-d' );
		return $meta;
	}

	/**
	 * Liest einen Plugin-Header.
	 *
	 * @param string $head Dateianfang.
	 * @param string $key  Header.
	 * @return string
	 */
	protected static function header( $head, $key ) {
		return preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $head, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * Changelog-Abschnitt aus readme.txt als einfaches HTML.
	 *
	 * @param string $readme Inhalt.
	 * @return string
	 */
	public static function changelog_from_readme( $readme ) {
		if ( ! preg_match( '/==\s*Changelog\s*==(.*?)(\n==\s|\z)/si', $readme, $m ) ) {
			return '';
		}
		$html = '';
		foreach ( preg_split( '/\r?\n/', trim( $m[1] ) ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $v ) ) {
				$html .= '<h4>' . esc_html( $v[1] ) . '</h4>';
			} elseif ( preg_match( '/^[*-]\s*(.+)$/', $line, $li ) ) {
				$html .= '<li>' . esc_html( wp_strip_all_tags( str_replace( '**', '', $li[1] ) ) ) . '</li>';
			}
		}
		return wp_kses_post( preg_replace( '/(<li>.*?<\/li>)+/s', '<ul>$0</ul>', $html ) );
	}

	// -------------------------------------------------------------------------
	// GitHub
	// -------------------------------------------------------------------------

	/**
	 * Releases aus dem neuesten GitHub-Release (zwischengespeichert, 1 Stunde).
	 *
	 * @param bool $force Cache ignorieren.
	 * @return array slug => release
	 */
	public static function github_releases( $force = false ) {
		$gh = self::github();
		if ( '' === $gh['repo'] ) {
			return array();
		}
		$cached = get_transient( self::GITHUB_CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$api = untrailingslashit( (string) apply_filters( 'bsls_github_api', 'https://api.github.com' ) );
		$res = self::github_request( $api . '/repos/' . $gh['repo'] . '/releases/latest' );
		if ( is_wp_error( $res ) ) {
			update_option( 'bsls_github_error', $res->get_error_message(), false );
			// Bei Fehler den letzten bekannten Stand weiterverwenden.
			$last = get_option( 'bsls_github_last', array() );
			set_transient( self::GITHUB_CACHE, $last, 10 * MINUTE_IN_SECONDS );
			return (array) $last;
		}
		$rel  = json_decode( $res, true );
		$out  = array();
		$body = isset( $rel['body'] ) ? (string) $rel['body'] : '';
		foreach ( isset( $rel['assets'] ) ? (array) $rel['assets'] : array() as $asset ) {
			// Erwartet: <slug>-<version>.zip
			if ( ! preg_match( '/^([a-z0-9-]+?)-(\d+(?:\.\d+)+(?:[-.][a-z0-9]+)?)\.zip$/i', (string) $asset['name'], $m ) ) {
				continue;
			}
			$out[ $m[1] ] = array(
				'slug'         => $m[1],
				'name'         => $m[1],
				'version'      => $m[2],
				'requires'     => '',
				'tested'       => '',
				'requires_php' => '',
				'changelog'    => wp_kses_post( wpautop( esc_html( $body ) ) ),
				'date'         => isset( $rel['published_at'] ) ? substr( (string) $rel['published_at'], 0, 10 ) : '',
				'source'       => 'github',
				'asset_url'    => (string) $asset['url'],
				'file'         => $m[1] . '-' . $m[2] . '.zip',
			);
		}
		delete_option( 'bsls_github_error' );
		update_option( 'bsls_github_last', $out, false );
		set_transient( self::GITHUB_CACHE, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * GitHub-API-Aufruf mit dem hinterlegten Token.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept-Header.
	 * @param int    $timeout Timeout.
	 * @return string|WP_Error Body.
	 */
	protected static function github_request( $url, $accept = 'application/vnd.github+json', $timeout = 15 ) {
		$gh      = self::github();
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'BlockSocial-License-Server',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== $gh['token'] ) {
			$headers['Authorization'] = 'Bearer ' . $gh['token'];
		}
		$res = wp_remote_get( $url, array( 'headers' => $headers, 'timeout' => $timeout, 'redirection' => 5 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return new WP_Error( 'bsls_github', sprintf( __( 'GitHub antwortet mit HTTP %d.', 'blocksocial-license-server' ), $code ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Lädt ein Release-Asset. GitHub leitet auf eine signierte Speicher-URL um,
	 * die KEINEN Authorization-Header verträgt – daher die Umleitung selbst folgen.
	 *
	 * @param string $url Asset-API-URL.
	 * @return string|WP_Error Dateiinhalt.
	 */
	protected static function github_asset( $url ) {
		$gh  = self::github();
		$res = wp_remote_get(
			$url,
			array(
				'headers'     => array(
					'Accept'        => 'application/octet-stream',
					'User-Agent'    => 'BlockSocial-License-Server',
					'Authorization' => 'Bearer ' . $gh['token'],
				),
				'timeout'     => 30,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return (string) wp_remote_retrieve_body( $res );
		}
		$location = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( $code < 300 || $code >= 400 || '' === $location ) {
			/* translators: %d: HTTP-Statuscode */
			return new WP_Error( 'bsls_github', sprintf( __( 'GitHub-Asset nicht abrufbar (HTTP %d).', 'blocksocial-license-server' ), $code ) );
		}
		$res = wp_remote_get( $location, array( 'timeout' => 120, 'headers' => array( 'User-Agent' => 'BlockSocial-License-Server' ) ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return is_wp_error( $res ) ? $res : new WP_Error( 'bsls_github', __( 'Download des GitHub-Assets fehlgeschlagen.', 'blocksocial-license-server' ) );
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Lokaler Pfad einer Release-ZIP; GitHub-Assets werden beim ersten Abruf
	 * geladen und zwischengespeichert.
	 *
	 * @param array $rel Release.
	 * @return string|WP_Error
	 */
	public static function local_file( array $rel ) {
		self::ensure_dir();
		$path = self::dir() . '/' . basename( (string) $rel['file'] );
		if ( file_exists( $path ) && filesize( $path ) > 0 ) {
			return $path;
		}
		if ( 'github' !== $rel['source'] ) {
			return new WP_Error( 'bsls_missing', __( 'Release-Datei fehlt.', 'blocksocial-license-server' ) );
		}
		$data = self::github_asset( $rel['asset_url'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$tmp = $path . '.part';
		file_put_contents( $tmp, $data ); // phpcs:ignore
		$meta = self::read_zip( $tmp );
		if ( is_wp_error( $meta ) || $meta['slug'] !== $rel['slug'] ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'bsls_zip', __( 'GitHub-Asset ist keine passende Plugin-ZIP.', 'blocksocial-license-server' ) );
		}
		rename( $tmp, $path );
		return $path;
	}

	// -------------------------------------------------------------------------
	// Download-Links
	// -------------------------------------------------------------------------

	/**
	 * Zeitlich begrenzter, signierter Download-Link (1 Stunde).
	 *
	 * @param string $slug       Plugin-Slug.
	 * @param int    $license_id Lizenz.
	 * @return string
	 */
	public static function download_url( $slug, $license_id ) {
		$exp = time() + HOUR_IN_SECONDS;
		$sig = hash_hmac( 'sha256', $slug . '|' . (int) $license_id . '|' . $exp, (string) get_option( 'bsls_secret' ) );
		return add_query_arg(
			array(
				'slug' => rawurlencode( $slug ),
				'lic'  => (int) $license_id,
				'exp'  => $exp,
				'sig'  => $sig,
			),
			rest_url( BSLS_REST_NS . '/download' )
		);
	}

	/**
	 * Prüft einen Download-Link.
	 *
	 * @param string $slug Slug.
	 * @param int    $lic  Lizenz.
	 * @param int    $exp  Ablauf.
	 * @param string $sig  Signatur.
	 * @return bool
	 */
	public static function verify_download( $slug, $lic, $exp, $sig ) {
		if ( (int) $exp < time() ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $slug . '|' . (int) $lic . '|' . (int) $exp, (string) get_option( 'bsls_secret' ) );
		return hash_equals( $expected, (string) $sig );
	}
}
