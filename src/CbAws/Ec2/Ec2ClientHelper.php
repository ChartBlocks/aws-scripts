<?php

namespace CbAws\Ec2;

use Aws\Ec2\Ec2Client;
use Exception;

class Ec2ClientHelper {

    /**
     *
     * @var \Aws\Ec2\Ec2Client
     */
    protected $client;

    public function __construct(Ec2Client $client) {
        $this->client = $client;
    }

    public function getInstanceById($instanceId) {
        $instances = $this->client->describeInstances(array(
                    'Filters' => array(
                        array('Name' => 'instance-id', 'Values' => array($instanceId)),
                    )
                ))->get('Reservations');

        if (count($instances) === 0) {
            throw new Exception('No matching instance found');
        } elseif (count($instances) > 1) {
            throw new Exception(count($instances) . ' instance(s) found');
        }

        return array_shift($instances[0]['Instances']);
    }

    public function getInstanceByHostname($hostname) {
        $instances = $this->client->describeInstances(array(
                    'Filters' => array(
                        array('Name' => 'private-dns-name', 'Values' => array("*$hostname*")),
                    )
                ))->get('Reservations');

        if (count($instances) === 0) {
            throw new Exception('No matching instance found');
        } elseif (count($instances) > 1) {
            var_dump($instances);
            exit;
            throw new Exception(count($instances) . ' instance(s) found');
        }

        return array_shift($instances[0]['Instances']);
    }

    /**
     * 
     * @param array $tags
     * @param string $key
     * @param string $default
     * @return string
     */
    public function getTag(array $tags, $key, $default = null) {
        foreach ($tags as $tag) {
            if ($tag['Key'] === $key) {
                return $tag['Value'];
            }
        }

        return $default;
    }

    /**
     * Get the instance name, or the hostname if no name set
     * 
     * @param array $instance
     * @return string
     */
    public function resolveInstanceName(array $instance) {
        return $this->getTag($instance['Tags'], 'Name', $instance['PrivateDnsName']);
    }

    public function getVolumesByInstance($instanceId) {
        $volumes = $this->client->describeVolumes(array(
                    'Filters' => array(
                        array('Name' => 'attachment.instance-id', 'Values' => array($instanceId)),
                    )
                ))->get('Volumes');

        return $volumes;
    }

    /**
     * 
     * @param array $attachments
     * @param string $instanceId
     * @return array
     * @throws Exception
     */
    public function getVolumeAttachmentForInstance(array $attachments, $instanceId) {
        foreach ($attachments as $attachment) {
            if ($attachment['InstanceId'] === $instanceId) {
                return $attachment;
            }
        }

        throw new Exception('Attachment not found');
    }

    public function getCompleteSnapshotsForVolumes(array $volumeIds) {
        return $this->client->describeSnapshots(array(
                    'Filters' => array(
                        array('Name' => 'volume-id', 'Values' => $volumeIds),
                        array('Name' => 'status', 'Values' => array('completed')),
                        array('Name' => 'tag-key', 'Values' => array('AutomatedBackup'))
                    )
                ))->get('Snapshots');
    }

}
