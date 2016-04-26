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
	// {{{ class constants

	/**
	 * Starting offset in seconds to look for pts_time packets with ffprobe
	 *
	 * If this is greater than the duration of the stream ffprove just seeks to
	 * the end of the stream.
	 */
	const DEFAULT_OFFSET = 432000; // 12 hours

	// }}}
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

		// This ffprobe command tries to seek to 12-hours into the stream and
		// then dumps pts_time values for each packet into a JSON-formatted
		// array. If the stream duration is less than 12-hours, only the final
		// packet is included in the array.
		$command = sprintf(
			'%s '.
				'-print_format json '.
				'-read_intervals %s%% '.
				'-select_streams a '.
				'-show_packets '.
				'-show_entries packet=pts_time '.
				'-v quiet '.
				'%s ',
			$this->bin,
			escapeshellarg(self::DEFAULT_OFFSET),
			escapeshellarg($workload['filename'])
		);

		$result = '';
		exec($command, $result);

		$result = implode('', $result);
		$result = json_decode($result, true);

		if ($result !== null &&
			is_array($result['packets']) &&
			count($result['packets']) > 0) {
			$packet = end($result['packets']);
			$duration = round($packet['pts_time']);
		} else {
			$this->logger->error('Error reading media duration.' . PHP_EOL);
			$job->sendFail('The ffprobe command failed.');
			return;
		}

		$response = array('duration' => $duration);

		$this->logger->info('done' . PHP_EOL);

		$job->sendSuccess(json_encode($response));
	}

	// }}}
}

?>
