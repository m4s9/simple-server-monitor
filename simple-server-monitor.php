<?php

//// < CONFIG >
// LIMITS
$cpuLimit = 0.5;       // load average (15min)
$memoryLimit = 60;     // percent
$swapLimit = 20;       // percent
$directoriesToWatch = ["/"];
$diskUsageLimit = 50;  // percent
$inodeUsageLimit = 50; // percent

// NOTIFY INTERVAL
$notifyInterval = 3*60*60;

// MAIL
$mailHost = "mailser.ver";
$mailPort = 1234;
$mailSMTPSecure = 'ssl'; // or tls
$mailUsername = "username";
$mailPassword = "password";
$mailFrom = 'from@add.ress';
$mailTo = ['to@add.ress', 'another_to@add.ress'];
$mailSubject = 'Server status alert';
//// < / CONFIG >

require('phpmailer/PHPMailer.php');
require('phpmailer/SMTP.php');
require('phpmailer/Exception.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getProcMemInfo($fields)
{
    $ret = [];

    $meminfo = file_get_contents("/proc/meminfo");
    if ($meminfo === false) {
        return $ret;
    }

    $rows = explode("\n", $meminfo);

    foreach ($rows as $row) {
        $row = trim($row);
        $rowData = explode(":", $row);
        if (count($rowData) == 2) {
            $value = false;
            $key = trim($rowData[0]);
            if (array_key_exists($key, $fields)) {
                preg_match_all('/[0-9]+/', trim($rowData[1]), $matches);
                if ($matches[0] && $matches[0][0]) {
                    $value = $matches[0][0];
                }
                $ret[$key] = $value;
            }
        }
    }

    return $ret;
}

$errors = [];

// READ DATA
$dataFile = "data.json";
$dataStore = @json_decode(file_get_contents($dataFile), true);
if (!$dataStore) {
    $dataStore = [];
}
if (!isset($dataStore['errors'])) {
    $dataStore['errors'] = [];
}

// CPU
$cpuLoadAverage = sys_getloadavg()[2];
if ($cpuLoadAverage >= $cpuLimit) {
    $errors[] = ['type' => 'cpu', 'msg' => "CPU load average in 15 min has been $cpuLoadAverage"];
}

// MEMORY
$memFields = [
    'MemTotal' => 1,
    'MemFree' => 1,
    'MemAvailable' => 1,
    'Buffers' => 1,
    'Cached' => 1,
    'SwapTotal' => 1,
    'SwapFree' => 1
];
$memInfo = getProcMemInfo($memFields);
if (count($memInfo) == count($memFields)) {
    // check mem
    $usedMem = $memInfo['MemTotal'] - $memInfo['MemAvailable'];
    $memUsagePercent = (int)(($usedMem / $memInfo['MemTotal'])*100);
    if ($memUsagePercent >= $memoryLimit) {
        $errors[] = ['type' => 'mem', 'msg' => "Memory usage is $memUsagePercent %"];
    }
    // check swap
    if ($memInfo['SwapTotal'] != 0) {
        $usedSwap = $memInfo['SwapTotal'] - $memInfo['SwapFree'];
        $swapUsagePercent = (int)(($usedSwap / $memInfo['SwapTotal'])*100);
        if ($swapUsagePercent >= $swapLimit) {
            $errors[] = ['type' => 'swap', 'msg' => "Swap usage is $swapUsagePercent %"];
        }
    }
} else {
    $errors[] = ['type' => 'memread', 'msg' => "Meminfo read failed: ".print_r($memInfo, true)];
}

// DISK
foreach ($directoriesToWatch as $dir) {
    // space
    $totalSpace = disk_total_space($dir);
    $spaceInUse = $totalSpace - disk_free_space($dir);
    $diskUsagePercent = (int)(($spaceInUse / $totalSpace)*100);
    if ($diskUsagePercent >= $diskUsageLimit) {
        $errors[] = ['type' => 'disk '.$dir, 'msg' => "Disk of '$dir' is using $diskUsagePercent % of its capacity"];
    }
    // inodes
    $cmd = 'df -hi "'.$dir.'" | awk \'{print $5}\' | tail -n 1 | sed \'s/%//\'';
    $inodeUsagePercent = system($cmd); // todo: supress echoing
    if ($inodeUsagePercent >= $inodeUsageLimit) {
        $errors[] = ['type' => 'inode '.$dir, 'msg' => "Disk of '$dir' is using $inodeUsagePercent % of its inodes"];
    }
}

$reportableErrors = [];
foreach ($errors as $error) {
    if (!isset($dataStore['errors'][$error['type']])) {
        $dataStore['errors'][$error['type']] = ['ts' => 0];
    }
    if ((time() - $notifyInterval) > $dataStore['errors'][$error['type']]['ts']) {
        $reportableErrors[] = $error;
        $dataStore['errors'][$error['type']]['ts'] = time();
    }
}

// SAVE DATA
file_put_contents($dataFile, json_encode($dataStore));

// REPORT ERRORS
if (count($reportableErrors) != 0) {
    $msg = "";
    foreach ($reportableErrors as $error) {
        echo $error['msg']."\n";
        $msg .= $error['msg']."\n";
    }
    
    // Instantiation and passing `true` enables exceptions
    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->SMTPDebug = 2;                                // Enable verbose debug output
        $mail->isSMTP();                                     // Set mailer to use SMTP
        $mail->Host       = $mailHost;                       // Specify main and backup SMTP servers
        $mail->SMTPAuth   = true;                            // Enable SMTP authentication
        $mail->Username   = $mailUsername;                   // SMTP username
        $mail->Password   = $mailPassword;                   // SMTP password
        $mail->SMTPSecure = $mailSMTPSecure;                 // Enable TLS encryption, `ssl` also accepted
        $mail->Port       = $mailPort;                       // TCP port to connect to

        //Recipients
        $mail->setFrom($mailFrom);
        foreach ($mailTo as $to) {
            $mail->addAddress($to);                          // Add a recipient(s)
        }

        // Content
        $mail->isHTML(false);
        $mail->Subject = $mailSubject;
        $mail->Body    = $msg;

        $send_ret = $mail->send();
        echo "Message has been sent ($send_ret)\n";
    } catch (Exception $e) {
        echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}\n";
    }
}
