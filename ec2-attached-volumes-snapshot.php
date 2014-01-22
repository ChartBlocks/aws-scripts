<?php

use Zend\Console\Getopt;
use Zend\Console\Console;
use Zend\Console\ColorInterface as Color;
use Aws\Ec2\Ec2Client;
use CbAws\Ec2\Ec2ClientHelper;

require(__DIR__ . '/vendor/autoload.php');

$console = Console::getInstance();
$opt = new Getopt(array(
    'key|O-s' => 'AWS access key',
    'secret|W-s' => 'AWS secret key',
    'instance|i-s' => 'Instance to backup',
    'region|r-s' => 'Region of instance',
    'exclude|e-s' => 'Exclude volumes/devices, comma delimited',
    'dry-run|d' => 'Dry run, do not delete anything',
        ));
$opt->parse();

try {
    $key = empty($opt->key) ? getenv('AWS_ACCESS_KEY') : $opt->key;
    $secret = empty($opt->secret) ? getenv('AWS_SECRET_KEY') : $opt->secret;
    $region = empty($opt->region) ? getenv('AWS_REGION') : $opt->region;
    $instanceId = $opt->instance;
    $exclusions = empty($opt->exclude) ? array() : array_map('trim', explode(',', $opt->exclude));
    $dryRun = (bool) $opt->{'dry-run'};

    $client = Ec2Client::factory(array(
                'key' => $key,
                'secret' => $secret,
                'region' => $region
    ));

    $clientHelper = new Ec2ClientHelper($client);

    if (empty($instanceId)) {
        $hostname = gethostname() . '.';
        $instance = $clientHelper->getInstanceByHostname($hostname);
        $instanceId = $instance['InstanceId'];
    } else {
        $instance = $clientHelper->getInstanceById($instanceId);
    }

    $instanceName = $clientHelper->resolveInstanceName($instance);
    $console->writeLine("Creating snapshots of $instanceName");

    $volumes = $clientHelper->getVolumesByInstance($instanceId);
    if (count($volumes) === 0) {
        throw new Exception('No volumes found');
    }

    $toBackup = array();
    foreach ($volumes as $volume) {
        $attachment = $clientHelper->getVolumeAttachmentForInstance($volume['Attachments'], $instanceId);
        $volumeId = $attachment['VolumeId'];
        $device = $attachment['Device'];

        if (in_array($volumeId, $exclusions) || in_array($device, $exclusions)) {
            $console->writeLine("Excluding $volumeId on $device", Color::YELLOW);
        } else {
            array_push($toBackup, array(
                'volumeId' => $volumeId,
                'device' => $device,
            ));

            $console->writeLine("Found $volumeId on $device", Color::GRAY);
        }
    }

    if (!$dryRun) {
        $console->writeLine("Starting snapshots");
        $snapshotIds = array();
        foreach ($toBackup as $backup) {
            $volumeId = $backup['volumeId'];
            $device = $backup['device'];
            $name = $instanceName . ' ' . $device;

            $result = $client->createSnapshot(array(
                'VolumeId' => $volumeId,
                'Description' => $name
            ));

            $snapshotId = $result['SnapshotId'];
            array_push($snapshotIds, $snapshotId);

            $console->writeLine("Saving $volumeId on $device to $snapshotId", Color::GRAY);
        }

        $snapshots = $client->describeSnapshots(array(
                    'SnapshotIds' => $snapshotIds
                ))->get('Snapshots');

        $console->writeLine('Waiting for snapshots to complete');
        $client->waitUntilSnapshotCompleted($snapshots);
        $console->writeLine('All snapshots complete', Color::GREEN);
    }
} Catch (Exception $e) {
    $console->writeLine($e->getMessage(), Color::RED);
    exit(1);
}
