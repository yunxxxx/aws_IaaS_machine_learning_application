<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// echo exec('whoami');

require '/var/www/html/vendor/autoload.php';

use Aws\Ec2\Ec2Client;
use Aws\Sqs\SqsClient;

// Set up the SQS client
$sqsClient = new SqsClient([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => '*',
        'secret' => '*',
    ]
]);

// Set up the EC2 client
$ec2Client = new Ec2Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => '*',
        'secret' => '*',
    ]
]);

$instanceCount = 0;
$instanceIds = [];
$processedMessageIds = []; // Array to store processed message IDs
$mutiChecker = 0;

// Poll SQS for messages
while (true) {
    $messages = $sqsClient->getQueueAttributes([
        'QueueUrl' => 'request SQS',
        'AttributeNames' => ['ApproximateNumberOfMessages'],
    ]);
    // Check if there are messages in the queue
    if (isset($messages['Attributes'])) {
        $numberOfMessages = $messages['Attributes']['ApproximateNumberOfMessages'];
        if ($numberOfMessages > 0) {
            $mutiChecker = 0;
            echo "Messages available in the SQS queue: $numberOfMessages\n"; // Debug message
            $newInstanceNum = $numberOfMessages - $instanceCount;
            for ($x = 1; $x <= $newInstanceNum; $x++) {
                if ($instanceCount < 20) {
                    $instanceCount++;
                    $instanceId = launchInstance($instanceCount);
                    $instanceIds[] = $instanceId;
                } else {
                    echo "Maximum instance count reached.\n";
                    break; // Break the loop if the maximum instance count is reached
                }
            }
        } else {
            echo "No messages in the SQS queue.\n"; // Debug message
            $mutiChecker = $mutiChecker + 1;
            if ($mutiChecker > 40) {
                if (!empty($instanceIds)) {
                    foreach ($instanceIds as $id) {
                        terminateInstance($id);
                    }
                    $instanceIds = [];
                    $instanceCount = 0;
                }
            }
        }
    } else {
        echo "Failed to retrieve queue attributes.\n";
        echo "No messages in the SQS queue.\n"; // Debug message
        $mutiChecker = $mutiChecker + 1;
        if ($mutiChecker > 40) {
            if (!empty($instanceIds)) {
                foreach ($instanceIds as $id) {
                    terminateInstance($id);
                }
                $instanceIds = [];
                $instanceCount = 0;
            }
        }
    }
    sleep(1);
}

function launchInstance($instanceCount) {
    global $ec2Client;

    // Define the shell commands or script to be executed on the instance
    $userDataScript = "#!/bin/bash\n";
    // $userDataScript .= "sudo -u ubuntu -i\n\n"; 
    $userDataScript .= "cd /var/www/html\n"; 
    $userDataScript .= "php index.php\n"; // Execute the PHP script

    // Base64 encode the UserData script
    $encodedUserData = base64_encode($userDataScript);

    // Launch an EC2 instance with your custom AMI and UserData script
    $result = $ec2Client->runInstances([
        'ImageId' => 'image',
        'InstanceType' => 't2.micro',
        'MinCount' => 1,
        'MaxCount' => 1,
        'KeyName' => 'my_key_pair',
        'UserData' => $encodedUserData, 
        'SecurityGroupIds' => ['sgID', 'sgID'],
        'TagSpecifications' => [
            [
                'ResourceType' => 'instance',
                'Tags' => [
                    [
                        'Key' => 'Name',
                        'Value' => 'app-tier-instance-' . $instanceCount
                    ],
                ]
            ]
        ],
    ]);
    // Extract instance ID from the result
    $instanceId = $result->get('Instances')[0]['InstanceId'];
    
    return $instanceId;
}

function terminateInstance($instanceId) {
    global $ec2Client;
    
    // Terminate the EC2 instance
    $ec2Client->terminateInstances([
        'InstanceIds' => [$instanceId],
    ]);
    
    echo "Instance $instanceId terminated.\n";
}
?>