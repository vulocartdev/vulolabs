<?php
/**
 * BackupManager class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\SiteHealth;

use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Real DB + file backups - Protect My Site's "Backups"/"Recovery" tiles.
 *
 * @class       BackupManager class
 * @version     1.0.0
 * @author      VuloLabs
 */
class BackupManager {

	private const QUEUE_OPTION = 'vulopilot_backup_queue';

	private const BATCH_HOOK = 'vulopilot_backup_process_batch';

	/**
	 * Elapsed-time budget per batch tick, in seconds.
	 */
	private const BATCH_SECONDS_BUDGET = 15;

	/**
	 * Max files enumerated across uploads/theme/plugins combined, per backup.
	 */
	private const MAX_BACKUP_FILES = 5000;

	/**
	 * Rows read per table dump chunk, to bound peak memory.
	 */
	private const DB_CHUNK_SIZE = 500;

	/**
	 * Access-protection stubs planted in the backups directory, filename => contents.
	 * Covers Apache and IIS; nginx needs a server-level rule instead.
	 */
	private const PROTECTION_FILES = array(
		'index.php'  => "<?php\n// Silence is golden.\n",
		'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
		'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
	);

	/**
	 * Backslash escapes a dumped string value can carry, escape letter => character.
	 */
	private const SQL_ESCAPES = array(
		'n' => "\n",
		'r' => "\r",
		'0' => "\0",
		'Z' => "\x1a",
	);

	/**
	 * BackupManager constructor.
	 */
	public function __construct() {
		add_action( self::BATCH_HOOK, array( $this, 'process_batch' ) );
	}

	/**
	 * Directory holding all installed plugins.
	 *
	 * @return string Absolute path.
	 */
	private function get_plugins_dir(): string {
		return dirname( untrailingslashit( VuloPilot()->plugin_path ) );
	}

	/**
	 * Plugin-owned backups storage directory, created and index-protected on first use.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	public function get_backup_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( trailingslashit( $upload_dir['basedir'] ) . 'vulopilot-backups' );

		wp_mkdir_p( $dir );

		// Block direct HTTP access to the backup archives and temp .sql dumps.
		foreach ( self::PROTECTION_FILES as $name => $contents ) {
			if ( ! file_exists( $dir . $name ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing fixed access-protection stubs into VuloPilot's own backups directory, not arbitrary user input.
				file_put_contents( $dir . $name, $contents );
			}
		}

		return $dir;
	}

	/**
	 * Resolves a stored backup's basename to an absolute path, never trusting client input.
	 *
	 * @param string $file_basename Stored `file_path` basename.
	 * @return string
	 */
	public function resolve_file_path( string $file_basename ): string {
		return $this->get_backup_dir() . basename( $file_basename );
	}

	/**
	 * Seeds a fresh backup job and kicks off its first batch tick.
	 *
	 * @param string $trigger_type 'manual'|'scheduled'|'pre_restore_safety'.
	 * @return int Real new `vulopilot_backups` row id.
	 */
	public function start_backup( string $trigger_type ): int {
		$zip_path = $this->get_backup_dir() . 'backup-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 6, false, false ) . '.zip';

		$repository = new BackupRepository();
		$backup_id  = $repository->insert(
			array(
				'status'       => 'queued',
				'trigger_type' => $trigger_type,
				'created_at'   => current_time( 'mysql', true ),
			)
		);

		update_option(
			self::QUEUE_OPTION,
			array(
				'backup_id' => $backup_id,
				'zip_path'  => $zip_path,
				// Kept in the system temp dir, not the web-accessible uploads folder: it holds a raw SQL dump until it is folded into the (protected) zip.
				'sql_path'  => trailingslashit( get_temp_dir() ) . 'vulopilot-tmp-' . wp_generate_password( 16, false, false ) . '.sql',
				'steps'     => $this->build_steps(),
				'started'   => false,
			),
			false
		);

		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time(), self::BATCH_HOOK );
		}

		return $backup_id;
	}

	/**
	 * Every real step this backup needs to perform, enumerated up front.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_steps(): array {
		global $wpdb;

		$steps = array();

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only introspection of this site's own table names.

		foreach ( (array) $tables as $table ) {
			$steps[] = array(
				'type'  => 'db_table',
				'table' => (string) $table,
			);
		}

		$remaining_budget = self::MAX_BACKUP_FILES;

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['basedir'] ) && is_dir( $upload_dir['basedir'] ) ) {
			$this->enumerate_directory(
				$steps,
				$upload_dir['basedir'],
				'files/uploads',
				$remaining_budget,
				trailingslashit( $upload_dir['basedir'] ) . 'vulopilot-backups'
			);
		}

		$theme_dir = get_stylesheet_directory();

		if ( is_dir( $theme_dir ) ) {
			$this->enumerate_directory( $steps, $theme_dir, 'files/theme/' . get_stylesheet(), $remaining_budget );
		}

		$plugins_dir = trailingslashit( $this->get_plugins_dir() );

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
			$plugin_slug = strtok( (string) $plugin_file, '/' );

			if ( $plugin_slug && is_dir( $plugins_dir . $plugin_slug ) ) {
				$this->enumerate_directory( $steps, $plugins_dir . $plugin_slug, 'files/plugins/' . $plugin_slug, $remaining_budget );
			}
		}

		return $steps;
	}

	/**
	 * Appends a `file` step for every real file under `$directory`.
	 *
	 * @param array<int, array<string, mixed>> $steps              Step list to append to, by reference.
	 * @param string                           $directory          Real absolute directory path.
	 * @param string                           $entry_prefix       Zip entry path prefix for this directory's own files.
	 * @param int                              $remaining_budget   Real remaining file budget, by reference.
	 * @param string|null                      $exclude_dir_prefix Real absolute path prefix to skip (this plugin's own backups directory).
	 * @return void
	 */
	private function enumerate_directory( array &$steps, string $directory, string $entry_prefix, int &$remaining_budget, ?string $exclude_dir_prefix = null ): void {
		if ( $remaining_budget <= 0 ) {
			return;
		}

		$iterator = $this->get_file_iterator( $directory, \RecursiveIteratorIterator::LEAVES_ONLY );

		if ( null === $iterator ) {
			return;
		}

		// Paths are compared and stored with forward slashes so this also works on Windows hosts.
		$exclude = $exclude_dir_prefix ? trailingslashit( wp_normalize_path( $exclude_dir_prefix ) ) : '';

		foreach ( $iterator as $file ) {
			if ( $remaining_budget <= 0 ) {
				break;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			$real_path = $file->getPathname();

			if ( '' !== $exclude && 0 === strpos( wp_normalize_path( $real_path ), $exclude ) ) {
				continue;
			}

			$steps[] = array(
				'type'   => 'file',
				'source' => $real_path,
				'entry'  => $entry_prefix . '/' . wp_normalize_path( $iterator->getSubPathname() ),
			);

			--$remaining_budget;
		}
	}

	/**
	 * Recursive iterator over a directory's contents.
	 *
	 * @param string $directory Real absolute directory path.
	 * @param int    $mode      A `RecursiveIteratorIterator` mode constant.
	 * @return \RecursiveIteratorIterator|null Null when the directory can't be read.
	 */
	private function get_file_iterator( string $directory, int $mode ): ?\RecursiveIteratorIterator {
		try {
			return new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
				$mode
			);
		} catch ( \Exception $exception ) {
			return null;
		}
	}

	/**
	 * Processes as many queued steps as fit in `BATCH_SECONDS_BUDGET`, then either
	 * reschedules itself or finalizes the archive once the queue is drained.
	 *
	 * @return void
	 */
	public function process_batch(): void {
		$queue = get_option( self::QUEUE_OPTION, null );

		if ( ! is_array( $queue ) || empty( $queue['backup_id'] ) ) {
			return;
		}

		$repository = new BackupRepository();
		$backup_id  = (int) $queue['backup_id'];

		if ( empty( $queue['started'] ) ) {
			$repository->update(
				$backup_id,
				array(
					'status'     => 'running',
					'started_at' => current_time( 'mysql', true ),
				)
			);
			$queue['started'] = true;
		}

		// Steps are walked by index and the queue is cut once afterwards.
		$steps = ! empty( $queue['steps'] ) ? $queue['steps'] : array();
		$total = count( $steps );
		$next  = 0;

		try {
			$zip = new \ZipArchive();

			if ( true !== $zip->open( $queue['zip_path'], \ZipArchive::CREATE ) ) {
				$this->fail_backup( $repository, $backup_id, 'Could not open backup archive for writing.' );
				return;
			}

			$started_at = microtime( true );

			while ( $next < $total && ( microtime( true ) - $started_at ) < self::BATCH_SECONDS_BUDGET ) {
				$this->process_step( $steps[ $next ], $zip, $queue['sql_path'] );
				++$next;
			}

			$zip->close();
		} catch ( \Throwable $exception ) {
			$this->fail_backup( $repository, $backup_id, $exception->getMessage() );
			return;
		}

		if ( $next < $total ) {
			$queue['steps'] = array_slice( $steps, $next );

			update_option( self::QUEUE_OPTION, $queue, false );
			wp_schedule_single_event( time() + 5, self::BATCH_HOOK );
			return;
		}

		$this->finalize_backup( $backup_id, $queue );
	}

	/**
	 * Abandons the current job: drops its queue and marks its row failed.
	 *
	 * @param BackupRepository $repository Backups table repository.
	 * @param int              $backup_id  Real `vulopilot_backups` row id.
	 * @param string           $message    Why the job failed.
	 * @return void
	 */
	private function fail_backup( BackupRepository $repository, int $backup_id, string $message ): void {
		delete_option( self::QUEUE_OPTION );

		$repository->update(
			$backup_id,
			array(
				'status'        => 'failed',
				'finished_at'   => current_time( 'mysql', true ),
				'error_message' => $message,
			)
		);
	}

	/**
	 * Executes one real step against the open archive.
	 *
	 * @param array<string, mixed> $step     One entry from the queue's own step list.
	 * @param \ZipArchive          $zip      Currently-open archive.
	 * @param string               $sql_path Real path to this backup's shared temp `.sql` file.
	 * @return void
	 * @throws \RuntimeException When a table dump can't be written.
	 */
	private function process_step( array $step, \ZipArchive $zip, string $sql_path ): void {
		if ( 'db_table' === $step['type'] ) {
			$this->dump_table_to_sql( (string) $step['table'], $sql_path );
			return;
		}

		if ( 'file' === $step['type'] && file_exists( $step['source'] ) ) {
			$zip->addFile( $step['source'], $step['entry'] );
		}
	}

	/**
	 * Appends one real table's own `DROP TABLE`/`CREATE TABLE`/`INSERT` statements to the
	 * shared temp `.sql` file.
	 *
	 * @param string $table    Real, already-known table name (from `SHOW TABLES`, never client input).
	 * @param string $sql_path Real path to this backup's shared temp `.sql` file.
	 * @return void
	 * @throws \RuntimeException When the temp file can't be opened.
	 */
	private function dump_table_to_sql( string $table, string $sql_path ): void {
		global $wpdb;

		// WP_Filesystem can't append, so native stream functions are used here instead.
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( $sql_path, 'a' );

		if ( ! $handle ) {
			throw new \RuntimeException( 'Could not open temporary SQL file for writing.' );
		}

		$create_row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- $table is always a real name read from SHOW TABLES, never client input; SHOW CREATE TABLE is read-only introspection for this backup export, not a schema change.

		$header = "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$table}`;\n";

		if ( $create_row && isset( $create_row[1] ) ) {
			$header .= $create_row[1] . ";\n\n";
		}

		fwrite( $handle, $header );

		$columns = '';
		$offset  = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, self::DB_CHUNK_SIZE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is always a real name read from SHOW TABLES; $offset/DB_CHUNK_SIZE are internal ints, never client input.

			$fetched = is_array( $rows ) ? count( $rows ) : 0;

			if ( 0 === $fetched ) {
				break;
			}

			$statements = '';

			foreach ( $rows as $row ) {
				// Every row of a table has the same columns, so they are listed once.
				if ( '' === $columns ) {
					$columns = '`' . implode( '`, `', array_keys( $row ) ) . '`';
				}

				$values = array();

				foreach ( $row as $value ) {
					$values[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $value ) ) . "'";
				}

				$statements .= "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ', ', $values ) . ");\n";
			}

			fwrite( $handle, $statements );

			$offset += self::DB_CHUNK_SIZE;
		} while ( $fetched >= self::DB_CHUNK_SIZE );

		fclose( $handle );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Folds the accumulated `.sql` dump into the archive as `database.sql`, marks the row
	 * `completed` with its real file size, and applies retention cleanup.
	 *
	 * @param int                  $backup_id Real `vulopilot_backups` row id.
	 * @param array<string, mixed> $queue     The now-drained queue option's own array.
	 * @return void
	 */
	private function finalize_backup( int $backup_id, array $queue ): void {
		$has_sql = file_exists( $queue['sql_path'] );
		$zip     = new \ZipArchive();

		if ( true === $zip->open( $queue['zip_path'], \ZipArchive::CREATE ) ) {
			if ( $has_sql ) {
				$zip->addFile( $queue['sql_path'], 'database.sql' );
			}

			$zip->close();
		}

		if ( $has_sql ) {
			wp_delete_file( $queue['sql_path'] );
		}

		( new BackupRepository() )->update(
			$backup_id,
			array(
				'status'      => 'completed',
				'file_path'   => basename( $queue['zip_path'] ),
				'file_size'   => wp_filesize( $queue['zip_path'] ),
				'finished_at' => current_time( 'mysql', true ),
			)
		);

		delete_option( self::QUEUE_OPTION );

		$this->apply_retention();

		do_action( 'vulopilot_backup_completed', $backup_id );
	}

	/**
	 * Drains the current batch queue synchronously, within this same request.
	 *
	 * @param int $max_iterations Real batch-tick cap.
	 * @return void
	 */
	public function run_queue_synchronously( int $max_iterations = 500 ): void {
		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$queue = get_option( self::QUEUE_OPTION, null );

			if ( ! is_array( $queue ) || empty( $queue['steps'] ) ) {
				break;
			}

			$this->process_batch();
		}
	}

	/**
	 * Real restore - Recovery's own destructive core.
	 *
	 * @param int $backup_id Real `vulopilot_backups` row id, must be `status='completed'`.
	 * @return true|\WP_Error
	 */
	public function restore( int $backup_id ) {
		$repository = new BackupRepository();
		$backup     = $repository->find( $backup_id );

		if ( ! $backup || 'completed' !== $backup['status'] || empty( $backup['file_path'] ) ) {
			return new \WP_Error( 'vulopilot_backup_not_ready', __( 'This backup is not available to restore.', 'vulopilot' ), array( 'status' => 409 ) );
		}

		$zip_path = $this->resolve_file_path( (string) $backup['file_path'] );

		if ( ! file_exists( $zip_path ) ) {
			return new \WP_Error( 'vulopilot_backup_file_missing', __( 'This backup\'s file could not be found on disk.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'vulopilot-restore-' . $backup_id . '-' . wp_generate_password( 6, false, false ) . '/';

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new \WP_Error( 'vulopilot_restore_tmp_dir_failed', __( 'Could not create a temporary directory to extract the backup into.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			$this->delete_directory_recursive( $tmp_dir );
			return new \WP_Error( 'vulopilot_restore_open_failed', __( 'Could not open this backup\'s archive.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		$zip->extractTo( $tmp_dir );
		$zip->close();

		$sql_file = $tmp_dir . 'database.sql';

		if ( file_exists( $sql_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading VuloPilot's own just-extracted backup file, not arbitrary user input.
			$sql = (string) file_get_contents( $sql_file );

			foreach ( explode( ";\n", $sql ) as $statement ) {
				$statement = trim( $statement );

				if ( '' === $statement ) {
					continue;
				}

				$result = $this->restore_sql_statement( $statement );

				if ( is_wp_error( $result ) ) {
					$this->delete_directory_recursive( $tmp_dir );

					return new \WP_Error(
						'vulopilot_restore_sql_failed',
						sprintf(
							/* translators: %s is the real database error that stopped the restore. */
							__( 'Restore stopped partway through the database import: %s', 'vulopilot' ),
							$result->get_error_message()
						),
						array( 'status' => 500 )
					);
				}
			}
		}

		$files_dir = $tmp_dir . 'files';

		if ( is_dir( $files_dir . '/uploads' ) ) {
			$this->copy_directory_recursive( $files_dir . '/uploads', wp_upload_dir()['basedir'] );
		}

		if ( is_dir( $files_dir . '/theme' ) ) {
			foreach ( (array) glob( $files_dir . '/theme/*', GLOB_ONLYDIR ) as $theme_backup_dir ) {
				$this->copy_directory_recursive( $theme_backup_dir, trailingslashit( get_theme_root() ) . basename( $theme_backup_dir ) );
			}
		}

		if ( is_dir( $files_dir . '/plugins' ) ) {
			foreach ( (array) glob( $files_dir . '/plugins/*', GLOB_ONLYDIR ) as $plugin_backup_dir ) {
				$this->copy_directory_recursive( $plugin_backup_dir, trailingslashit( $this->get_plugins_dir() ) . basename( $plugin_backup_dir ) );
			}
		}

		$this->delete_directory_recursive( $tmp_dir );

		// Raw $wpdb->query() calls above bypass update_option()'s cache invalidation.
		wp_cache_flush();

		( new ActivityLogRepository() )->insert(
			array(
				'event_type'  => 'backup.restored',
				'object_type' => 'backup',
				'object_id'   => $backup_id,
				'actor_type'  => 'user',
				'actor_id'    => get_current_user_id(),
				'message'     => sprintf(
					/* translators: 1: backup id, 2: real date/time the backup was created. */
					__( 'Restored the site from backup #%1$d (created %2$s)', 'vulopilot' ),
					$backup_id,
					$backup['created_at']
				),
				'severity'    => 'high',
			)
		);

		return true;
	}

	/**
	 * Real recursive copy - overwrites files in `$destination` from `$source` in place.
	 *
	 * @param string $source      Real absolute source directory.
	 * @param string $destination Real absolute destination directory.
	 * @return void
	 */
	private function copy_directory_recursive( string $source, string $destination ): void {
		wp_mkdir_p( $destination );

		$iterator = $this->get_file_iterator( $source, \RecursiveIteratorIterator::LEAVES_ONLY );

		if ( null === $iterator ) {
			return;
		}

		$destination = trailingslashit( $destination );
		$made_dir    = '';

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$destination_path = $destination . wp_normalize_path( $iterator->getSubPathname() );
			$destination_dir  = dirname( $destination_path );

			// Files come directory by directory, so a folder is only created when it changes.
			if ( $destination_dir !== $made_dir ) {
				wp_mkdir_p( $destination_dir );
				$made_dir = $destination_dir;
			}

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- restoring VuloPilot's own just-extracted, plugin-controlled backup archive contents back into place.
			copy( $file->getPathname(), $destination_path );
		}
	}

	/**
	 * Whether a dumped table belongs to this site, i.e. carries its own prefix.
	 *
	 * @param string $table Table name from the dump.
	 * @return bool
	 */
	private function is_site_table( string $table ): bool {
		global $wpdb;

		return 0 === strpos( $table, $wpdb->prefix );
	}

	/**
	 * Replays one statement of a backup's `database.sql`.
	 *
	 * @param string $statement One statement from the dump, without its trailing `;`.
	 * @return true|\WP_Error
	 */
	private function restore_sql_statement( string $statement ) {
		global $wpdb;

		// Drop the "-- Table: name" comment lines that head each table's block.
		$statement = trim( (string) preg_replace( '/^--[^\n]*\n?/m', '', $statement ) );

		if ( '' === $statement ) {
			return true;
		}

		if ( preg_match( '/^DROP TABLE IF EXISTS `([A-Za-z0-9_]+)`$/', $statement, $matches ) ) {
			if ( ! $this->is_site_table( $matches[1] ) ) {
				return new \WP_Error( 'vulopilot_restore_foreign_table', __( 'The backup refers to a table outside this site.', 'vulopilot' ) );
			}

			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $matches[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- restoring this site's own table from its backup.

			return $wpdb->last_error ? new \WP_Error( 'vulopilot_restore_drop_failed', $wpdb->last_error ) : true;
		}

		if ( preg_match( '/^CREATE TABLE `([A-Za-z0-9_]+)`/', $statement, $matches ) ) {
			if ( ! $this->is_site_table( $matches[1] ) ) {
				return new \WP_Error( 'vulopilot_restore_foreign_table', __( 'The backup refers to a table outside this site.', 'vulopilot' ) );
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			dbDelta( $statement );

			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $matches[1] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking the table was created.

			if ( $exists ) {
				return true;
			}

			return new \WP_Error( 'vulopilot_restore_create_failed', $wpdb->last_error ? $wpdb->last_error : $matches[1] );
		}

		if ( preg_match( '/^INSERT INTO `([A-Za-z0-9_]+)` \((.+?)\) VALUES \((.*)\)$/s', $statement, $matches ) ) {
			if ( ! $this->is_site_table( $matches[1] ) ) {
				return new \WP_Error( 'vulopilot_restore_foreign_table', __( 'The backup refers to a table outside this site.', 'vulopilot' ) );
			}

			preg_match_all( '/`([A-Za-z0-9_]+)`/', $matches[2], $column_matches );
			$values = $this->parse_sql_values( $matches[3] );

			if ( null === $values || count( $values ) !== count( $column_matches[1] ) ) {
				return new \WP_Error( 'vulopilot_restore_bad_row', $matches[1] );
			}

			$inserted = $wpdb->insert( $matches[1], array_combine( $column_matches[1], $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring this site's own rows from its backup.

			return false === $inserted ? new \WP_Error( 'vulopilot_restore_insert_failed', $wpdb->last_error ) : true;
		}

		return new \WP_Error( 'vulopilot_restore_unsupported', __( 'The backup contains a statement this version cannot restore.', 'vulopilot' ) );
	}

	/**
	 * Reads the value list of one dumped `INSERT` - `NULL` or a single-quoted
	 * string with backslash escapes, separated by commas - back into an array.
	 *
	 * @param string $value_list The text between `VALUES (` and the closing `)`.
	 * @return array<int, string|null>|null Null when the text is not in that format.
	 */
	private function parse_sql_values( string $value_list ): ?array {
		$values = array();
		$length = strlen( $value_list );
		$i      = 0;

		while ( $i < $length ) {
			if ( 'NULL' === substr( $value_list, $i, 4 ) ) {
				$values[] = null;
				$i       += 4;
			} elseif ( "'" === $value_list[ $i ] ) {
				$value = '';
				++$i;

				while ( $i < $length && "'" !== $value_list[ $i ] ) {
					// Plain text is copied up to the next quote or backslash in one step
					// instead of a character at a time - post content can be megabytes.
					$run = strcspn( $value_list, "'\\", $i );

					if ( $run > 0 ) {
						$value .= substr( $value_list, $i, $run );
						$i     += $run;
					} elseif ( $i + 1 < $length ) {
						// A backslash escape.
						++$i;
						$value .= self::SQL_ESCAPES[ $value_list[ $i ] ] ?? $value_list[ $i ];
						++$i;
					} else {
						// A lone backslash at the very end.
						$value .= $value_list[ $i ];
						++$i;
					}
				}

				if ( $i >= $length ) {
					return null;
				}

				++$i;
				$values[] = $value;
			} else {
				return null;
			}

			if ( $i < $length ) {
				if ( ', ' !== substr( $value_list, $i, 2 ) ) {
					return null;
				}

				$i += 2;
			}
		}

		return $values;
	}

	/**
	 * Real recursive delete - cleans up restore()'s own temp extraction directory.
	 *
	 * @param string $directory Real absolute directory path.
	 * @return void
	 */
	private function delete_directory_recursive( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = $this->get_file_iterator( $directory, \RecursiveIteratorIterator::CHILD_FIRST );

		if ( null === $iterator ) {
			return;
		}

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- cleaning up VuloPilot's own plugin-controlled temp restore directory, not arbitrary user input.
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				wp_delete_file( $file->getPathname() );
			}
		}

		rmdir( $directory );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Deletes the oldest completed backups (row + real file) beyond
	 * `backup_retention_count`, so disk usage stays bounded.
	 *
	 * @return void
	 */
	private function apply_retention(): void {
		$settings = wp_parse_args( get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );
		$keep     = absint( $settings['backup_retention_count'] );

		if ( $keep < 1 ) {
			$keep = 5;
		}

		$repository = new BackupRepository();
		$expired    = $repository->get_completed_beyond_retention( $keep );

		if ( ! $expired ) {
			return;
		}

		$storage = VuloPilot()->backup_storage_manager;

		foreach ( $expired as $row ) {
			if ( ! empty( $row['file_path'] ) ) {
				wp_delete_file( $this->resolve_file_path( (string) $row['file_path'] ) );
			}

			// No-ops for local/never-uploaded backups.
			$storage->delete_remote_copy( $row );

			$repository->delete( (int) $row['id'] );
		}
	}
}
