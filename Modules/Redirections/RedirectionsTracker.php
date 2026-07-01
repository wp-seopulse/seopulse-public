<?php

/**
 * 404 error tracker
 *
 * @package SEOPulse\Modules\Redirections
 * @since 1.0.0
 */

declare(strict_types=1);

namespace SEOPulse\Modules\Redirections;

use SEOPulse\Core\Constants\Options;
use SEOPulse\Core\Traits\CurrentUrlTrait;

/**
 * RedirectionsTracker class
 */
class RedirectionsTracker {

	use CurrentUrlTrait;

	/**
	 * Option name — aligned with Options::REDIRECTIONS_404
	 *
	 * @var string
	 */
	private string $option_name = 'seopulse_404_logs';

	/**
	 * Maximum number of 404 log entries to keep.
	 *
	 * @var int
	 */
	private int $max_logs = 500;

	/**
	 * Tracks a 404 error
	 *
	 * @return void
	 */
	public function track_404(): void {
		if ( ! is_404() ) {
			return;
		}

		// Respect the "Enable 404 tracking" toggle in Archives → 404 Page settings.
		$archiveSettings = get_option( 'seopulse_archive_settings', array() );
		if ( ! ( $archiveSettings['error_404']['track_404'] ?? true ) ) {
			return;
		}

		$url        = sanitize_text_field( $this->get_current_url() );
		$referer    = wp_get_referer();
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

		// Ignore known bots
		if ( $this->is_bot( $user_agent ) ) {
			return;
		}

		$log_entry = array(
			'url'        => $url,
			'referer'    => $referer ? sanitize_text_field( $referer ) : '',
			'user_agent' => $user_agent,
			'ip'         => $this->hash_ip( $this->get_client_ip() ),
			'timestamp'  => current_time( 'mysql' ),
			'count'      => 1,
		);

		$this->save_log( $log_entry );
	}

	/**
	 * Retrieves all 404 logs
	 *
	 * @return array
	 */
	public function get_all_404s(): array {
		$logs = get_option( $this->option_name, array() );

		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Retrieves the total number of 404 errors
	 *
	 * @return int
	 */
	public function get_404_count(): int {
		$logs = $this->get_all_404s();

		$total = 0;
		foreach ( $logs as $log ) {
			$total += $log['count'] ?? 1;
		}

		return $total;
	}

	/**
	 * Retrieves the top 404 errors
	 *
	 * @param int $limit Number of results
	 * @return array
	 */
	public function get_top_404s( int $limit = 10 ): array {
		$logs = $this->get_all_404s();

		usort(
			$logs,
			function ( $a, $b ) {
				return ( $b['count'] ?? 0 ) - ( $a['count'] ?? 0 );
			},
		);

		return array_slice( $logs, 0, $limit );
	}

	/**
	 * Deletes a 404 log
	 *
	 * @param string $url URL to delete
	 * @return bool
	 */
	public function delete_404( string $url ): bool {
		$logs = $this->get_all_404s();

		$logs = array_filter(
			$logs,
			function ( $log ) use ( $url ) {
				return $log['url'] !== $url;
			},
		);

		return update_option( $this->option_name, array_values( $logs ) );
	}

	/**
	 * Deletes all 404 logs
	 *
	 * @return bool
	 */
	public function delete_all_404s(): bool {
		return delete_option( $this->option_name );
	}

	/**
	 * Saves a log entry
	 *
	 * @param array $entry Entry to save
	 * @return void
	 */
	private function save_log( array $entry ): void {
		$logs = $this->get_all_404s();

		// Check if the URL already exists
		$existing_index = null;
		foreach ( $logs as $index => $log ) {
			if ( $log['url'] === $entry['url'] ) {
				$existing_index = $index;
				break;
			}
		}

		if ( $existing_index !== null ) {
			// Increment the counter
			$logs[ $existing_index ]['count']     = ( $logs[ $existing_index ]['count'] ?? 1 ) + 1;
			$logs[ $existing_index ]['last_seen'] = $entry['timestamp'];
		} else {
			// Add new entry
			$logs[] = $entry;
		}

		// Limit the number of logs
		if ( count( $logs ) > $this->max_logs ) {
			usort(
				$logs,
				function ( $a, $b ) {
					return strtotime( $b['timestamp'] ?? 'now' ) - strtotime( $a['timestamp'] ?? 'now' );
				},
			);
			$logs = array_slice( $logs, 0, $this->max_logs );
		}

		update_option( $this->option_name, $logs );
	}

	/**
	 * Retrieves the client IP (REMOTE_ADDR only — ignores proxy headers to avoid spoofing).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	/**
	 * Hashes an IP for privacy (GDPR-friendly).
	 *
	 * @param string $ip Raw IP address.
	 * @return string Hashed IP.
	 */
	private function hash_ip( string $ip ): string {
		if ( empty( $ip ) ) {
			return '';
		}

		return wp_hash( $ip );
	}

	/**
	 * Checks if it is a bot
	 *
	 * @param string $user_agent User agent
	 * @return bool
	 */
	private function is_bot( string $user_agent ): bool {
		$bots = array(
			'googlebot',
			'bingbot',
			'slurp',
			'duckduckbot',
			'baiduspider',
			'yandexbot',
			'facebookexternalhit',
			'twitterbot',
			'rogerbot',
			'linkedinbot',
			'embedly',
			'quora link preview',
			'showyoubot',
			'outbrain',
			'pinterest',
			'slackbot',
			'vkShare',
			'W3C_Validator',
			'redditbot',
			'applebot',
			'whatsapp',
			'flipboard',
			'tumblr',
			'bitlybot',
			'skypeuripreview',
			'nuzzel',
			'discordbot',
			'qwantify',
			'pinterestbot',
			'bitrix',
		);

		$user_agent_lower = strtolower( $user_agent );

		foreach ( $bots as $bot ) {
			if ( strpos( $user_agent_lower, $bot ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
