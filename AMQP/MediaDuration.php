<?php

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
 * @copyright 2015 silverorange
 * @license   http://www.opensource.org/licenses/mit-license.html MIT
 */
class AMQP_MediaDuration extends SiteAMQPApplication
{
    // {{{ class constants

    /**
     * Starting offset in seconds to look for pts_time packets with ffprobe
     *
     * If this is greater than the duration of the stream ffprobe just seeks to
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
            $this->sendFailAndLog($job, 'Job was not formatted properly.');
            return;
        }

        $content = '';

        if (!file_exists($workload['filename'])) {
            $this->sendFailAndLog($job, 'Media file was not found.');
            return;
        }

        if (!is_file($workload['filename'])
            || !is_readable($workload['filename'])
        ) {
            $this->sendFailAndLog($job, 'Media file could not be opened.');
            return;
        }

        $this->logger->info(
            'Calculating duration of "{filename}" ... ',
            array(
                'filename' => $workload['filename']
            )
        );

        $data = $this->getDurationAndFormatFromHeader($workload['filename']);
        if ($data === null) {
            $this->sendFailAndLog(
                $job,
                'Unable to read duration and format from media header.'
            );
            return;
        }

        if (in_array('mp3', explode(',', strtolower($data['format'])))) {
            // If the file is a MP3 file, ignore the metadata duration and
            // calculate duration based on raw packets.
            $duration = $this->getDurationFromPackets($workload['filename']);
            if ($duration === null) {
                $this->sendFailAndLog(
                    $job,
                    'Unable to read duration from MP3 file packets.'
                );
                return;
            }
        } else {
            // Otherwise, use the duration from the file metadata.
            $duration = $data['duration'];
        }

        $response = array('duration' => $duration);

        $this->logger->info('done' . PHP_EOL);

        $job->sendSuccess(json_encode($response));
    }

    // }}}
    // {{{ protected function getDurationAndFormatFromHeader()

    /**
     * Gets duration and format of media from the file header
     *
     * This just reads the media file's header to get metadata about the
     * audio stream.
     *
     * @param string $filename the filename of the media for which to get
     *                         the duration and format.
     *
     * @return array an array contining two elements - 'duration' and
     *               'format'. If the metadata could not be read for the file,
     *               null is returned.
     */
    protected function getDurationAndFormatFromHeader($filename)
    {
        $data = null;

        $command = sprintf(
            '%s '.
                '-print_format json '.
                '-select_streams a '.
                '-show_entries format=format_name:format=duration '.
                '-v quiet '.
                '%s ',
            $this->bin,
            escapeshellarg($filename)
        );

        $result = '';
        exec($command, $result);

        $result = implode('', $result);
        $result = json_decode($result, true);

        if ($result !== null
            && isset($result['format'])
            && is_array($result['format'])
            && isset($result['format']['format_name'])
            && isset($result['format']['duration'])
        ) {
            $data = array(
                'format' => $result['format']['format_name'],
                'duration' => $this->parseDuration(
                    $result['format']['duration']
                ),
            );
        }

        return $data;
    }

    // }}}
    // {{{ protected function getDurationFromPackets()

    /**
     * Gets duration of media by reading audio packets
     *
     * This ignores any metadata from the file header and just looks at the
     * time from the last packet.
     *
     * @param string $filename the filename of the media for which to get
     *                         the duration.
     *
     * @return integer the duration of the media or null if the duration
     *                 could not be determined.
     */
    protected function getDurationFromPackets($filename)
    {
        $duration = null;

        // This ffprobe command tries to seek to 12-hours into the stream and
        // then dumps pts_time values for each packet into a JSON-formatted
        // array. If the stream duration is less than 12-hours, only the final
        // packet is included in the array.
        $command = sprintf(
            '%s '.
                '-print_format json '.
                '-read_intervals %s%% '.
                '-select_streams a '.
                '-show_entries packet=pts_time '.
                '-v quiet '.
                '%s ',
            $this->bin,
            escapeshellarg(self::DEFAULT_OFFSET),
            escapeshellarg($filename)
        );

        $result = '';
        exec($command, $result);

        $result = implode('', $result);
        $result = json_decode($result, true);

        if ($result !== null
            && is_array($result['packets'])
            && count($result['packets']) > 0
        ) {
            $packet = end($result['packets']);
            $duration = $this->parseDuration($packet['pts_time']);
        }

        return $duration;
    }

    // }}}
    // {{{ protected function parseDuration()

    /**
     * Converts a string duration into an integer duration
     *
     * @param string $duration the raw duration returned by ffprobe.
     *
     * @return integer the duration in seconds as an integer.
     */
    protected function parseDuration($duration)
    {
        return (integer)round($duration);
    }

    // }}}
    // {{{ protected function sendFailAndLog()

    /**
     * Sends a job failure message and logs the same message to the error log
     *
     * @param SiteAMQPJob $job     the job to mark as failed.
     * @param string      $message optional. The error message.
     *
     * @return void
     */
    protected function sendFailAndLog(SiteAMQPJob $job, $message = '')
    {
        if ($message != '') {
            $this->logger->error($message . PHP_EOL);
        }
        $job->sendFail($message);
    }

    // }}}
}

?>
