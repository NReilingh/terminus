<?php

namespace Pantheon\Terminus\Models;

use Pantheon\Terminus\Friends\EnvironmentInterface;
use Pantheon\Terminus\Friends\EnvironmentTrait;
use Robo\Common\ConfigAwareTrait;
use Robo\Contract\ConfigAwareInterface;
use Pantheon\Terminus\Exceptions\TerminusException;

/**
 * Class Backup
 * @package Pantheon\Terminus\Models
 */
class Backup extends TerminusModel implements ConfigAwareInterface, EnvironmentInterface
{
    use ConfigAwareTrait;
    use EnvironmentTrait;

    public static $pretty_name = 'backup';

    /**
     * Determines whether the backup has been completed or not
     *
     * @return boolean True if backup is completed.
     */
    public function backupIsFinished()
    {
        return (
            ($this->get('size') != 0)
            && (
                ($this->get('finish_time') != null)
                || ($this->get('timestamp') != null)
            )
        );
    }

    /**
     * Returns the bucket name for this backup
     *
     * @return string
     */
    public function getBucket()
    {
        $bucket = 'pantheon-backups';
        if (strpos($this->getConfig()->get('host'), 'onebox') !== false) {
            $bucket = "onebox-$bucket";
        }
        return $bucket;
    }

    /**
     * Returns the date the backup was completed
     *
     * @return string Timestamp completion time or "Pending"
     */
    public function getDate()
    {
        if (!is_null($this->get('finish_time'))) {
            $datetime = $this->get('finish_time');
        } elseif (!is_null($this->get('timestamp'))) {
            $datetime = $this->get('timestamp');
        } else {
            return 'Pending';
        }
        return date($this->getConfig()->get('date_format'), $datetime);
    }

    /**
     * Returns the type of initiator of the backup
     *
     * @return string Either "manual" or "automated"
     */
    public function getInitiator()
    {
        preg_match("/.*_(.*)/", $this->get('folder'), $automation_match);
        return (isset($automation_match[1]) && ($automation_match[1] == 'automated')) ? 'automated' : 'manual';
    }

    /**
     * @return string[]
     */
    public function getReferences()
    {
        return [$this->id, $this->get('filename'),];
    }

    /**
     * Returns the size of the backup in MB
     *
     * @return string A number (an integer or a float) followed by 'MB'.
     */
    public function getSizeInMb()
    {
        $size_string = '0';
        if ($this->get('size') != null) {
            $size = $this->get('size') / 1048576;
            if ($size > 0.1) {
                $size_string = sprintf('%.1fMB', $size);
            } elseif ($size > 0) {
                $size_string = '0.1MB';
            }
        }
        return $size_string;
    }

    /**
     * Gets the URL of a backup
     *
     * @return string
     */
    public function getUrl()
    {
        $env = $this->getEnvironment();
        $path = sprintf(
            'sites/%s/environments/%s/backups/catalog/%s/%s/s3token',
            $env->getSite()->id,
            $env->id,
            $this->get('folder'),
            $this->get('type')
        );
        $options  = ['method' => 'post', 'form_params' => ['method' => 'get',],];
        $response = $this->request()->request($path, $options);
        return $response['data']->url;
    }

    /**
     * Restores this backup
     *
     * @return Workflow
     * @throws TerminusException
     */
    public function restore()
    {
        $type = $this->get('type');
        switch ($type) {
            case 'code':
                $wf_name = 'restore_code';
                break;
            case 'files':
                $wf_name = 'restore_files';
                break;
            case 'database':
                $wf_name = 'restore_database';
                break;
            default:
                throw new TerminusException('This backup has no archive to restore.');
                break;
        }
        $modified_id = str_replace("_$type", '', $this->id);
        $env = $this->getEnvironment();
        $workflow = $env->getWorkflows()->create(
            $wf_name,
            [
                'params' => [
                    'key' => "{$env->getSite()->id}/{$env->id}/{$modified_id}/{$this->get('filename')}",
                    'bucket' => $this->getBucket(),
                ],
            ]
        );
        return $workflow;
    }

    /**
     * Formats the object into an associative array for output
     *
     * @return array Associative array of data for output
     */
    public function serialize()
    {
        return [
            'file'      => $this->get('filename'),
            'size'      => $this->getSizeInMb(),
            'date'      => $this->getDate(),
            'initiator' => $this->getInitiator(),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function parseAttributes($data)
    {
        list($data->scheduled_for, $data->archive_type, $data->type) = explode('_', $data->id);
        return $data;
    }
}
