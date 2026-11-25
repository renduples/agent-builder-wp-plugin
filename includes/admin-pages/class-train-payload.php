<?php
/**
 * Train_Payload builder for admin page REST payload.
 *
 * @package Agent_Builder
 */

declare(strict_types=1);

namespace Agentic\Admin_Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Train_Payload {

	public static function build( string $tab ): array {
		$is_pro   = false;
		$concepts = array();
		if ( class_exists( \Agentic\Okf_Store::class ) ) {
			foreach ( \Agentic\Okf_Store::list_concepts( '', true ) as $c ) {
				$concepts[] = array(
					'id'      => (string) ( $c['id'] ?? '' ),
					'title'   => (string) ( $c['title'] ?? $c['id'] ?? '' ),
					'type'    => (string) ( $c['type'] ?? '' ),
					'example' => ! empty( $c['example'] ),
					'status'  => (string) ( $c['status'] ?? '' ),
				);
			}
		}
		return array(
			'page'        => 'train-data',
			'tab'         => $tab,
			'title'       => __( 'Knowledge', 'agentstructor' ),
			'description' => __( 'Wiki concepts (OKF) your agents can look up.', 'agentstructor' ),
			'is_pro'      => false,
			'concepts'    => $concepts,
			'tabs'        => array_values(
				array_filter(
					array(
						array(
							'id'    => 'wiki',
							'label' => __( 'Knowledge Wiki', 'agentstructor' ),
							'url'   => admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ),
						),
						$is_pro ? array(
							'id'    => 'vector',
							'label' => __( 'Vector Store', 'agentstructor' ),
							'url'   => admin_url( 'admin.php?page=agentic-train-data&tab=vector' ),
						) : null,
					)
				)
			),
			'manage_url'  => admin_url( 'admin.php?page=agentic-train-data&tab=wiki' ),
		);
	}
}
