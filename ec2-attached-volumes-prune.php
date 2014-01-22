<?php

use Zend\Console\Getopt;
use Zend\Console\Console;
use Zend\Console\ColorInterface as Color;
use Aws\Ec2\Ec2Client;
use CbAws\Ec2\Ec2ClientHelper;

require('vendor/autoload.php');

$console = Console::getInstance();
$opt = new Getopt(array(
    'key|O-s' => 'AWS access key',
    'secret|W-s' => 'AWS secret key',
    'hostname|h-s' => 'Hostname to backup',
    'region|r-s' => 'Region of host',
    'age|t-s' => 'Max age, in seconds (default = 1 week)',
    'dry-run|d' => 'Dry run, do not delete anything',
        ));
$opt->parse();

try {
    $key = empty($opt->key) ? getenv('AWS_ACCESS_KEY') : $opt->key;
    $secret = empty($opt->secret) ? getenv('AWS_SECRET_KEY') : $opt->secret;
    $region = empty($opt->region) ? getenv('AWS_REGION') : $opt->region;
    $hostname = empty($opt->hostname) ? gethostname() : $opt->hostname;
    $age = empty($opt->age) ? 86400 * 7 : (int) $opt->age;
    $dryRun = (bool) $opt->{'dry-run'};

    if ($age <= 0) {
        $console->writeLine('Invalid age parameter', Color::RED);
        echo $opt->getUsageMessage();
        exit(1);
    }

    $maxAge = new DateTime();
    $maxAge->sub(new DateInterval('PT' . $age . 'S'));

    $console->writeLine("Pruning snapshots on $hostname older than " . $maxAge->format('c'));

    $client = Ec2Client::factory(array(
                'key' => $key,
                'secret' => $secret,
                'region' => $region
    ));

    $clientHelper = new Ec2ClientHelper($client);

    $instance = $clientHelper->getInstanceByHostname($hostname);
    $instanceId = $instance['InstanceId'];
    $instanceName = $clientHelper->resolveInstanceName($instance);

    $volumes = $clientHelper->getVolumesByInstance($instanceId);
    if (count($volumes) === 0) {
        throw new Exception('No volumes found');
    }

    $volumeIds = array();
    foreach ($volumes as $volume) {
        array_push($volumeIds, $volume['VolumeId']);
    }

    $console->writeLine("Found " . count($volumeIds) . " volumes", Color::GRAY);
    $snapshots = $clientHelper->getCompleteSnapshotsForVolumes($volumeIds);
    $console->writeLine("Found " . count($snapshots) . " snapshots", Color::GRAY);

    $toDelete = array();
    foreach ($snapshots as $snapshot) {
        $created = new DateTime($snapshot['StartTime']);
        if ($created < $maxAge) {
            array_push($toDelete, $snapshot['SnapshotId']);
        }
    }

    $console->writeLine("Found " . count($toDelete) . " old snapshots", Color::YELLOW);


    foreach ($toDelete as $snapshotId) {
        $console->writeLine('Deleting' . ($dryRun ? ' (dry-run) ' : ' ') . $snapshotId, Color::GRAY);
        if (!$dryRun) {
            $client->deleteSnapshot(array('SnapshotId' => $snapshotId));
        }
    }

    $console->writeLine('Done' . ($dryRun ? ' (dry-run)' : ''), Color::GREEN);
} Catch (Exception $e) {
    $console->writeLine($e->getMessage(), Color::RED);
    exit(1);
}