<?php

/* vim: set noexpandtab tabstop=4 shiftwidth=4 foldmethod=marker: */

require_once 'Site/SiteAMQPApplication.php';

/**
 * Worker that calculates duration of media using ffmpeg
 *
 * The worker accepts a JSON-encoded job that contains a single field:
 * ```
 * {
 *   "filename" : "/path/to/media.mp3"
 * }
 * ```
 *
 * The worker returns a JSON message with the duration in seconds on success:
 * ```
 * {
 *   "duration" : 12345
 * }
 * ```
 *
 * @package   AMQP_MediaDuration
 * @license   http://www.opensource.org/licenses/mit-license.html MIT
 * @copyright 2015 silverorange
 */
class AMQP_MediaDuration extends SiteAMQPApplication
{
	// {{{ protected properties

	/**
	 * @var string
	 */
	protected $bin = '';

	// }}}
	// {{{ protected function init()

	protected function init()
	{
		parent::init();
		$this->bin = trim(`which ffprobe`);
	}

	// }}}
	// {{{ protected function doWork()

	/**
	 * Expects JSON in the form:
	 * {
	 *   "filename": "/absolute/path/to/file"
	 * }
	 *
	 * @param SiteAMQPJob $job
	 *
	 * @return void
	 */
	protected function doWork(SiteAMQPJob $job)
	{
		$workload = json_decode($job->getBody(), true);

		if ($workload === null || !isset($workload['filename'])) {
			$this->logger->error('Job was not formatted properly.' . PHP_EOL);
			$job->sendFail('Job was not formatted properly.');
			return;
		}

		$content = '';

		if (!file_exists($workload['filename'])) {
			$this->logger->error('Media file was not found.' . PHP_EOL);
			$job->sendFail('Media file was not found.');
			return;
		}

		if (!is_file($workload['filename']) ||
			!is_readable($workload['filename'])) {
			$this->logger->error('Media file could not be opened.' . PHP_EOL);
			$job->sendFail('Media file could not be opened.');
			return;
		}

		$this->logger->info(
			'Calculating duration of "{filename}" ... ',
			array(
				'filename' => $workload['filename']
			)
		);

		$command = sprintf(
			'%s '.
				'-select_streams a '.
				'-show_packets '.
				'-show_entries packet=pts_time '.
				'-v quiet '.
				'%s '.
			'| '.
			'grep pts_time '.
			'| '.
			'tail -1 '.
			'| '.
			'cut -d "=" -f 2 ',
			$this->bin,
			escapeshellarg($workload['filename'])
		);

		$duration = intval(
			round(
				trim(
					shell_exec($command)
				)
			)
		);

		$response = array('duration' => $duration);

		$this->logger->info('done' . PHP_EOL);

		$job->sendSuccess(json_encode($response));
	}

	// }}}
}

?>
