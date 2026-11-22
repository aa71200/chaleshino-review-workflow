<?php

namespace Chaleshino\CallReview;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Timing, locked writes and ranking adapt the corresponding Chaleshino 1.0.0 GPL-2.0-or-later workflows. */
final class Workflow {

	public static function error( string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'chlr_request', $message, array( 'status' => $status ) );
	}

	private static function db_error(): WP_Error {
		return self::error( __( 'ذخیره یا خواندن داده انجام نشد. دوباره تلاش کنید.', 'chaleshino-call-review' ), 503 );
	}

	public static function missing(): WP_Error {
		return self::error( __( 'مورد درخواستی پیدا نشد.', 'chaleshino-call-review' ), 404 );
	}

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function field( mixed $value, int $limit, bool $multiline = false ): string {
		$value      = is_string( $value ) && strlen( $value ) <= $limit * 4 ? $value : '';
		$value      = $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $characters ) && count( $characters ) <= $limit ? $value : '';
	}

	private static function locked( int $id, callable $action ): mixed {
		global $wpdb;
		if ( ! Store::ready() || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::db_error();
		}
		try {
			$call   = $id ? Store::call( $id, true ) : null;
			$result = $id && ! $call ? self::missing() : $action( $call );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				return self::db_error();
			}
			return $result;
		} finally {
			$wpdb->query( 'ROLLBACK' );
		}
	}

	public static function judging( object $call ): bool {
		$now = self::now();
		return (bool) $call->is_public && ! $call->result_revision && $call->submissions_end <= $now && $now < $call->judging_end;
	}

	public static function can_judge( object $entry, object $call ): bool {
		return is_user_logged_in()
			&& 'approved' === $entry->status
			&& (bool) $call->is_public
			&& get_current_user_id() !== (int) $entry->owner_id
			&& current_user_can( 'read' )
			&& null !== Store::assignment( (int) $entry->id, get_current_user_id() );
	}

	private static function change_entry( int $id, callable $action ): mixed {
		$entry = Store::entry( $id );
		if ( ! $entry ) {
			return self::missing();
		}
		return self::locked(
			(int) $entry->call_id,
			static function ( $call ) use ( $id, $action ) {
				$entry = Store::entry( $id );
				return $entry ? $action( $entry, $call ) : self::missing();
			}
		);
	}

	public static function score( int $id, array $payload ): mixed {
		$value = $payload['score'] ?? '';
		$value = is_scalar( $value ) && ! is_bool( $value ) ? strtr(
			trim( (string) $value ),
			array_combine(
				preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٫', -1, PREG_SPLIT_NO_EMPTY ),
				str_split( '01234567890123456789.' )
			)
		) : '';
		if ( ! preg_match( '/\A\d{1,3}(?:\.\d{1,2})?\z/', $value ) || (float) $value > 100 ) {
			return self::error( __( 'امتیاز باید بین ۰ و ۱۰۰ و حداکثر با دو رقم اعشار باشد.', 'chaleshino-call-review' ) );
		}
		$note = self::field( $payload['note'] ?? '', 1500, true );
		return self::change_entry(
			$id,
			static function ( $entry, $call ) use ( $id, $value, $note ) {
				global $wpdb;
				if ( ! self::can_judge( $entry, $call ) ) {
					return self::missing();
				}
				if ( ! self::judging( $call ) ) {
					return self::error( __( 'ثبت امتیاز فقط در مهلت داوری امکان‌پذیر است.', 'chaleshino-call-review' ), 409 );
				}
				$ok = $wpdb->update(
					Store::tables()['assignments'],
					array(
						'score'      => $value,
						'note'       => $note,
						'updated_at' => self::now(),
					),
					array(
						'entry_id'  => $id,
						'judge_id'  => get_current_user_id(),
						'is_active' => 1,
					)
				);
				return false === $ok ? self::db_error() : array( 'id' => $id );
			}
		);
	}

	public static function ranking( int $call ): array|WP_Error {
		global $wpdb;
		$t    = Store::tables();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.receipt, e.status, COUNT(u.ID) AS assigned, COUNT(CASE WHEN u.ID IS NOT NULL THEN a.score END) AS scored,
			 ROUND(AVG(CASE WHEN u.ID IS NOT NULL THEN a.score END), 2) AS average
			 FROM `' . esc_sql( $t['entries'] ) . '` e LEFT JOIN `' . esc_sql( $t['assignments'] ) . '` a ON a.entry_id = e.id AND a.is_active = 1
			 LEFT JOIN `' . esc_sql( $wpdb->users ) . "` u ON u.ID = a.judge_id
			 WHERE e.call_id = %d AND e.status IN ('approved','pending')
			 GROUP BY e.id, e.receipt, e.status ORDER BY average DESC, e.id ASC",
				$call
			)
		);
		if ( $wpdb->last_error ) {
			return self::db_error();
		}
		$rank       = 0;
		$previous   = null;
		$incomplete = 0;
		$result     = array();
		foreach ( $rows as $row ) {
			if ( 'approved' !== $row->status || ! (int) $row->assigned || $row->assigned !== $row->scored ) {
				++$incomplete;
				continue;
			}
			if ( $row->average !== $previous ) {
				$rank = count( $result ) + 1;
			}
			$previous = $row->average;
			$result[] = array(
				'rank'    => $rank,
				'receipt' => $row->receipt,
				'score'   => $row->average,
			);
		}
		return array(
			'rows'       => $result,
			'incomplete' => $incomplete,
		);
	}

	public static function fingerprint( array $ranking ): string {
		return hash( 'sha256', wp_json_encode( $ranking ) );
	}

	public static function results( int $id, string $action, string $fingerprint ): mixed {
		return self::locked(
			$id,
			static function ( $call ) use ( $id, $action, $fingerprint ) {
				global $wpdb;
				$t        = Store::tables();
				$revision = (int) $call->result_revision;
				if ( 'revoke' === $action && $revision ) {
					$ok   = $wpdb->update(
						$t['results'],
						array(
							'revoked_by' => get_current_user_id(),
							'revoked_at' => self::now(),
						),
						array(
							'call_id'  => $id,
							'revision' => $revision,
						)
					);
					$next = 0;
				} elseif ( 'publish' === $action && ! $revision && $call->is_public && self::now() >= $call->judging_end ) {
					$ranking = self::ranking( $id );
					if ( is_wp_error( $ranking ) ) {
						return $ranking;
					}
					if ( $ranking['incomplete'] || ! $ranking['rows'] || ! hash_equals( self::fingerprint( $ranking ), $fingerprint ) ) {
						return self::error( __( 'اثر بررسی‌نشده، داوری ناقص یا تغییر نتیجه وجود دارد. پیش‌نمایش را دوباره بررسی کنید.', 'chaleshino-call-review' ), 409 );
					}
					$last = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(revision) FROM `' . esc_sql( $t['results'] ) . '` WHERE call_id = %d', $id ) );
					if ( $wpdb->last_error ) {
						return self::db_error();
					}
					$next     = 1 + (int) $last;
					$snapshot = wp_json_encode( $ranking['rows'] );
					$ok       = $snapshot && $wpdb->insert(
						$t['results'],
						array(
							'call_id'      => $id,
							'revision'     => $next,
							'snapshot'     => $snapshot,
							'published_by' => get_current_user_id(),
							'published_at' => self::now(),
						)
					);
				} else {
					return self::error( __( 'انتشار فقط پس از پایان داوری و از پیش‌نمایش قابل انجام است.', 'chaleshino-call-review' ), 409 );
				}
				if ( ! $ok || false === $wpdb->update( $t['calls'], array( 'result_revision' => $next ), array( 'id' => $id ) ) ) {
					return self::db_error();
				}
				return array( 'revision' => $next );
			}
		);
	}

	public static function published( object $call ): array {
		if ( ! $call->is_public || ! $call->result_revision ) {
			return array();
		}
		global $wpdb;
		$t    = Store::tables();
		$json = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT r.snapshot FROM `' . esc_sql( $t['results'] ) . '` r INNER JOIN `' . esc_sql( $t['calls'] ) . '` c ON c.id = r.call_id AND c.result_revision = r.revision
			 WHERE c.id = %d AND c.is_public = 1 AND r.revoked_at IS NULL',
				$call->id
			)
		);
		$rows = json_decode( (string) $json, true );
		return is_array( $rows ) ? $rows : array();
	}

}
