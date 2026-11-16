<?php
/**
 * Job Processor Interface
 *
 * Interface that all job processors must implement.
 *
 * @package    Agent_Builder
 * @subpackage Includes
 * @author     Siteloom Team <support@agentic-plugin.com>
 * @license    GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://agentic-plugin.com
 * @since      0.2.0
 *
 * php version 8.1
 */

declare(strict_types=1);

namespace Agentic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Processor Interface
 */
interface Job_Processor_Interface {

	/**
	 * Execute the job
	 *
	 * @param array    $request_data   Job input data.
	 * @param callable $progress_callback Callback to update progress.
	 * @return array Job result data.
	 * @throws \Exception If job execution fails.
	 */
	public function execute( array $request_data, callable $progress_callback ): array;
}
