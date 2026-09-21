<?php
/**
 * Site Brief checker: media library images over 1MB.
 *
 * @package    Agent_Builder
 * @subpackage Site_Brief
 * @since      3.4.2
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic\Site_Brief;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Card for oversized images, Approve-queued to compress_image (capped).
 */
class Checker_Oversized_Media extends Site_Brief_Checker {

	/**
	 * Size threshold in bytes (1 MB).
	 */
	public const MIN_BYTES = 1048576;

	/**
	 * Max attachments included in one Approve job.
	 */
	public const BATCH_CAP = 10;

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'oversized_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tools(): array {
		return array( 'find_oversized_images', 'get_media_storage_report' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_agent(): string {
		return 'wordpress-assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_severity(): int {
		return 45;
	}

	/**
	 * {@inheritdoc}
	 */
	public function run( Site_Brief_Runner $runner ): array {
		$report = $runner->observe_tool( 'get_media_storage_report', array() );
		$dims   = $runner->observe_tool(
			'find_oversized_images',
			array(
				'max_width'  => 2560,
				'max_height' => 2560,
				'limit'      => 50,
			)
		);

		$over = array();

		foreach ( (array) ( $report['largest_files'] ?? array() ) as $file ) {
			$id      = (int) ( $file['id'] ?? 0 );
			$size_mb = (float) ( $file['size_mb'] ?? 0 );
			$bytes   = (int) round( $size_mb * 1048576 );
			if ( $id <= 0 || $bytes < self::MIN_BYTES ) {
				continue;
			}
			$over[ $id ] = array(
				'id'       => $id,
				'filename' => (string) ( $file['filename'] ?? '' ),
				'bytes'    => $bytes,
			);
		}

		foreach ( (array) ( $dims['images'] ?? array() ) as $image ) {
			$id     = (int) ( $image['attachment_id'] ?? 0 );
			$size_b = (int) round( ( (float) ( $image['size_kb'] ?? 0 ) ) * 1024 );
			if ( $id <= 0 || $size_b < self::MIN_BYTES ) {
				continue;
			}
			if ( ! isset( $over[ $id ] ) ) {
				$over[ $id ] = array(
					'id'       => $id,
					'filename' => (string) ( $image['filename'] ?? '' ),
					'bytes'    => $size_b,
				);
			}
		}

		if ( empty( $over ) ) {
			return array();
		}

		uasort(
			$over,
			static function ( array $a, array $b ): int {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		$over  = array_values( $over );
		$count = count( $over );
		$shown = array_slice( $over, 0, self::BATCH_CAP );
		$names = array();
		foreach ( $shown as $item ) {
			$mb      = round( $item['bytes'] / 1048576, 1 );
			$names[] = $item['filename'] . ' (' . $mb . ' MB)';
		}

		$first = $shown[0];

		return array(
			$this->card(
				array(
					'title'            => sprintf(
						/* translators: %d: number of images. */
						_n( '%d image is over 1 MB', '%d images are over 1 MB', $count, 'agent-builder' ),
						$count
					),
					'evidence'         => implode( ', ', $names ),
					'evidence_payload' => array_map(
						static function ( array $item ): array {
							return array(
								'id'    => $item['id'],
								'bytes' => $item['bytes'],
							);
						},
						$shown
					),
					'proposed_action'  => __( 'Queue compress_image for the largest file (max 10 per Approve). The scan does not rewrite files.', 'agent-builder' ),
					'action_risk'      => 'medium',
					'raw'              => $shown,
				)
			),
		);
	}
}
