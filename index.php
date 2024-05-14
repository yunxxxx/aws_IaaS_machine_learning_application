<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// echo exec('whoami');

require '/var/www/html/vendor/autoload.php';

use Aws\Sqs\SqsClient;

// Set up the SQS client
$sqsClient = new SqsClient([
    'version' => 'latest',
    'region' => 'us-east-1', // Replace 'your-region' with your AWS region
    'credentials' => [
        'key' => '*',
        'secret' => '*',
    ]
]);

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['inputFile'])) {
    // Process the uploaded file
    $uploadedFile = $_FILES['inputFile'];
    $fileContent = file_get_contents($uploadedFile['tmp_name']);
    $filename = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);

    // Encode the file content to base64
    $encodedFileContent = base64_encode($fileContent);

    // Send message to SQS queue
    try {
        $result = $sqsClient->sendMessage([
            'QueueUrl' => 'sqs link',
            'MessageBody' => json_encode(['filename' => $filename, 'fileContent' => $encodedFileContent]),
        ]);
        // Poll the result queue until a response is received
        // echo "Message sent, wating for it get back\n";
        $response = null;
        $resultQueueUrl = 'sqs link';
        $foundChecker = 0;
        do {
            sleep(1);
            $messages = $sqsClient->receiveMessage([
                'QueueUrl' => $resultQueueUrl,
                'MaxNumberOfMessages' => 10,
                'VisibilityTimeout' => 1,
                'WaitTimeSeconds' => 20,
            ]);

            if (!empty($messages['Messages'])) {
                foreach ($messages['Messages'] as $message) {
                    $messageBody = json_decode($message['Body'], true);
                    $resultName = $messageBody['filename'];
                    $resultBody = $messageBody['fileContent'];
                    if ($resultName == $filename) {
                        $foundChecker = 1;
                        // Delete the message from the queue
                        $sqsClient->deleteMessage([
                            'QueueUrl' => $resultQueueUrl,
                            'ReceiptHandle' => $message['ReceiptHandle']
                        ]);
                        break;
                    }
                }
            }
            if ($foundChecker == 1) {
                break;
            }
        } while ($foundChecker == 0);
        echo $resultName;
        echo ":";
        echo $resultBody;
    } catch (Aws\Exception\SqsException $e) {
        // Handle SQS exceptions
        http_response_code(500); // Internal Server Error
        echo "Error: Unable to send message to SQS queue.";
    }
} else {
    http_response_code(400); // Bad Request
    echo "Bad Request.";
}
?>
