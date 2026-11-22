<?php

namespace Chaleshino\CallReview;

defined( 'ABSPATH' ) || exit;

final class Store {
	const SCHEMA = '1';

	public static function tables(): array {
		global $wpdb;
		$prefix = $wpdb->prefix . 'chlr_';
		return array(
			'calls'       => $prefix . 'calls',
			'entries'     => $prefix . 'entries',
			'assignments' => $prefix . 'assignments',
			'results'     => $prefix . 'results',
		);
	}

	public static function activate(): void {
		if ( is_multisite() || ! self::install() ) {
			wp_die( esc_html__( 'فراخوان‌یار به وردپرس تک‌سایته و جدول‌های سالم InnoDB نیاز دارد.', 'chaleshino-call-review' ), '', array( 'back_link' => true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( MANAGE ) ) {
			$admin->add_cap( MANAGE );
			update_option( 'chlr_manage_cap_owned', true, false );
		}
		if ( ! get_option( 'chlr_storage_id' ) ) {
			add_option( 'chlr_storage_id', wp_generate_uuid4(), '', false );
		}
	}

	public static function maybe_upgrade(): void {
		$stored = get_option( 'chlr_schema' );
		if ( ! is_multisite() && is_string( $stored ) && (int) $stored < (int) self::SCHEMA ) {
			self::install();
		}
	}

	private static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			array(
				"CREATE TABLE {$t['calls']} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				title varchar(190) NOT NULL,
				instructions text NOT NULL,
				opens_at datetime NOT NULL,
				submissions_end datetime NOT NULL,
				judging_end datetime NOT NULL,
				is_public tinyint unsigned NOT NULL DEFAULT 0,
				page_id bigint unsigned NOT NULL DEFAULT 0,
				result_revision bigint unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) ENGINE=InnoDB $collate;",
				"CREATE TABLE {$t['entries']} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				call_id bigint unsigned NOT NULL,
				owner_id bigint unsigned NOT NULL,
				receipt char(24) NOT NULL,
				title varchar(190) NOT NULL,
				description text NOT NULL,
				file_key varchar(80) NOT NULL,
				file_mime varchar(40) NOT NULL,
				file_bytes bigint unsigned NOT NULL,
				status varchar(12) NOT NULL DEFAULT 'pending',
				reason text NOT NULL,
				submitted_at datetime NOT NULL,
				reviewed_by bigint unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY call_owner (call_id,owner_id),
				UNIQUE KEY receipt (receipt),
				KEY call_status (call_id,status,id)
			) ENGINE=InnoDB $collate;",
				"CREATE TABLE {$t['assignments']} (
				entry_id bigint unsigned NOT NULL,
				judge_id bigint unsigned NOT NULL,
				is_active tinyint unsigned NOT NULL DEFAULT 1,
				score decimal(5,2) DEFAULT NULL,
				note text NOT NULL,
				assigned_by bigint unsigned NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (entry_id,judge_id),
				KEY judge_active (judge_id,is_active,entry_id)
			) ENGINE=InnoDB $collate;",
				"CREATE TABLE {$t['results']} (
				call_id bigint unsigned NOT NULL,
				revision bigint unsigned NOT NULL,
				snapshot longtext NOT NULL,
				published_by bigint unsigned NOT NULL,
				published_at datetime NOT NULL,
				revoked_by bigint unsigned NOT NULL DEFAULT 0,
				revoked_at datetime DEFAULT NULL,
				PRIMARY KEY  (call_id,revision)
			) ENGINE=InnoDB $collate;",
			)
		);

		if ( ! self::healthy() || ! self::unique_keys_exist() ) {
			return false;
		}
		update_option( 'chlr_schema', self::SCHEMA, false );
		return true;
	}

	public static function healthy(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	private static function unique_keys_exist(): bool {
		global $wpdb;
		$t        = self::tables();
		$required = array(
			$t['entries']     => array(
				'call_owner' => 'call_id,owner_id',
				'receipt'    => 'receipt',
			),
			$t['assignments'] => array( 'PRIMARY' => 'entry_id,judge_id' ),
			$t['results']     => array( 'PRIMARY' => 'call_id,revision' ),
		);
		foreach ( $required as $table => $keys ) {
			foreach ( $keys as $name => $expected ) {
				$columns = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX',
						$table,
						$name
					)
				);
				if ( implode( ',', $columns ) !== $expected ) {
					return false;
				}
			}
		}
		return true;
	}

	public static function ready(): bool {
		return ! is_multisite() && self::SCHEMA === get_option( 'chlr_schema' ) && self::healthy();
	}

	public static function call( int $id, bool $lock = false ): ?object {
		global $wpdb;
		$table = self::tables()['calls'];
		if ( $lock ) {
			return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d FOR UPDATE', $id ) );
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d', $id ) );
	}

	public static function entry( int $id ): ?object {
		global $wpdb;
		$table = self::tables()['entries'];
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE id = %d', $id ) );
	}

	public static function assignment( int $entry, int $judge ): ?object {
		global $wpdb;
		$table = self::tables()['assignments'];
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( $table ) . '` WHERE entry_id = %d AND judge_id = %d AND is_active = 1', $entry, $judge ) );
	}
}
