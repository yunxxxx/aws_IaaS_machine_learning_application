<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// echo exec('whoami');

require '/var/www/html/vendor/autoload.php';

use Aws\Sqs\SqsClient;
use Aws\S3\S3Client;

// Set up the SQS client
$sqsClient = new SqsClient([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => '*',
        'secret' => '*',
    ]
]);

// Set up the S3 client
$s3Client = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'credentials' => [
        'key' => '*',
        'secret' => '*',
    ]
]);

// Poll SQS for messages
while (true) {
    echo "Polling SQS for messages...\n"; // Debug message
    // Receive messages from the SQS queue
    $messages = $sqsClient->receiveMessage([
        'QueueUrl' => 'request SQS',
        'MaxNumberOfMessages' => 1, // Adjust as needed
        'WaitTimeSeconds' => 1,
        'VisibilityTimeout' => 100,
    ]);

    // Check if there are messages in the queue
    if (!empty($messages['Messages'])) {
        echo "Received messages from SQS queue...\n"; // Debug message
        foreach ($messages['Messages'] as $message) {
            // Extract information from the message
            $messageBody = json_decode($message['Body'], true);
            $filename = $messageBody['filename'] . '.jpg'; // Assuming it's a JPG image
            $fileContent = base64_decode($messageBody['fileContent']);

            // Save the image to the local directory
            $localImagePath = '/home/ubuntu/' . $filename;
            file_put_contents($localImagePath, $fileContent);

            // Upload the file to S3
            $s3Client->putObject([
                'Bucket' => 'bucket name',
                'Key' => $filename,
                'Body' => $fileContent,
            ]);

            // Run the face recognition script
            chdir('/home/ubuntu/');
            $result = shell_exec("python3 /home/ubuntu/face_recognition.py $localImagePath");

            // Send the result to a different SQS queue
            try {
                $sqsClient->sendMessage([
                    'QueueUrl' => 'message out link', 
                    'MessageBody' => json_encode(['filename' => $messageBody['filename'], 'fileContent' => $result]),
                ]);
                echo "Result sent to a different SQS queue.\n"; // Debug message
                echo $result . "\n";
                echo $messageBody['filename'] . "\n";
            } catch (Aws\Sqs\Exception\SqsException $e) {
                // Handle the exception gracefully
                echo "Error sending message to SQS queue: " . $e->getMessage() . "\n";
            }

            // Upload the result to S3
            $s3Client->putObject([
                'Bucket' => '1220397048-out-bucket',
                'Key' => $filename,
                'Body' => $result,
                'ACL' => 'private',
            ]);
            // Delete the message from the original queue
            try {
                $sqsClient->deleteMessage([
                    'QueueUrl' => 'sqs url',
                    'ReceiptHandle' => $message['ReceiptHandle'],
                ]);
                echo "Original message processed and deleted from SQS queue.\n"; // Debug message
            } catch (Aws\Sqs\Exception\SqsException $e) {
                // Handle the exception gracefully
                echo "Error deleting message from SQS queue: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "No messages in the SQS queue.\n"; // Debug message
    }
}
?>