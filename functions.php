<?php
defined('_SFTPGO') or die;

use Amp\Http\Server\Response;
use Amp\Http\Status;

function isAllowedIP($remoteIP) {
    global $allowed_ips;

    logMessage('Starting Execution Process');

    if (array_search($remoteIP, $allowed_ips) !== false) {
        logMessage('Web Execution Mode...' . $remoteIP . ' is allowed.');
        return true;
    }

    logMessage('Web Execution Mode...' . $remoteIP . ' is not allowed.');

    return false;
}

function authenticateUser($data) {
    logMessage('Before getData()');
    $data = getData($data);
    logMessage('After getData()');

    if (!empty($data)) {

        try {
            global $connections;

            foreach($connections as $connectionName => $connection) {

                logMessage('Before connection attempt to ' . $connectionName);
                $connection->connect();
                logMessage('After connection attempt to ' . $connectionName);

                $configuration = $connection->getConfiguration();
                $baseDn = $configuration->get('base_dn');

                $organizationalUnit = $baseDn;

                $user = $connection->query()
                    ->in($organizationalUnit)
                    ->where('samaccountname', '=', $data['username'])
                    ->first();

                if ($user) {
                    logMessage('Username exists: ' . $data['username']);
                    // Our user is a member of one of the allowed groups.
                    // Continue with authentication.
                    $userDistinguishedName = $user['distinguishedname'][0];

                    logMessage('Before authentication attempt for: ' . $data['username']);
                    if ($connection->auth()->attempt($userDistinguishedName, $data['password'])) {
                        // User has been successfully authenticated.
                        logMessage('After authentication attempt for: ' . $data['username'] . ' (success!)');
                        $output = createResponseObject($connectionName, $data['username']);
                        return createResponse($output);
                    } else {
                        // Username or password is incorrect.
                        logMessage('After authentication attempt for: ' . $data['username'] . ' (failed!)');
                        return denyRequest();
                    }
                }

                logMessage('User lookup failed for: ' . $data['username']);
            }

        } catch (\LdapRecord\Auth\BindException $e) {
            $error = $e->getDetailedError();

            //echo $error->getErrorCode();
            //echo $error->getErrorMessage();
            //echo $error->getDiagnosticMessage();
        }
    }

    return denyRequest();
}

function createResponseObject($connectionName, $username) {
    global $home_directories, $virtual_folders, $default_output_object;

    $userHomeDirectory = str_replace('#USERNAME#', $username, $home_directories[$connectionName]);

    $output = $default_output_object;
    $output['username'] = $username;
    $output['home_dir'] = $userHomeDirectory;

    if (isset($virtual_folders[$connectionName])) {
        $output['virtual_folders'] = $virtual_folders[$connectionName];

        foreach ($output['virtual_folders'] as &$virtual_folder) {
            $virtual_folder['name'] = str_replace('#USERNAME#', $username, $virtual_folder['name']);
            $virtual_folder['mapped_path'] = str_replace('#USERNAME#', $username, $virtual_folder['mapped_path']);
        }
    }

    return $output;
}

function getData($data) {
    if (defined('_SFTPGO_DEBUG') && _SFTPGO_DEBUG === true) {
        global $debug_object;
        logMessage('Using $debug_object from configuration.php (authentication may fail if this object does not have correct credentials at the moment.)');
        $data = $debug_object;
    }

    if (is_string($data)) {
        $data = json_decode($data, true);
    }

    return $data;
}

function createResponse($output) {
    logMessage('Authentication Successful');

    return new Response(Status::OK, [
        "content-type" => "application/json",
    ], json_encode($output)
    );
}

function denyRequest() {
    logMessage('Authentication Failed');
    return new Response(Status::INTERNAL_SERVER_ERROR);
}

function canConnectToDirectories() {
    try {
        global $connections;

        foreach($connections as $connectionName => $connection) {

            $connection->connect();

            echo "Can connect to: " . $connectionName . '<br />';
        }

    } catch (\LdapRecord\Auth\BindException $e) {
        $error = $e->getDetailedError();

        echo "Can't connect to: " . $connectionName . '<br />';
        echo $error->getErrorCode() . '<br />';
        echo $error->getErrorMessage() . '<br />';
        echo $error->getDiagnosticMessage() . '<br />';
    }
}

function homeDirectoryEntriesExist() {

    global $connections, $home_directories;

    foreach($connections as $connectionName => $connection) {

        if (isset($home_directories[$connectionName])) {
            echo "Home Directory Entry Exists for: " . $connectionName . '<br />';
        } else {
            echo "Missing Home Directory Entry for: " . $connectionName . '<br />';
        }
    }
}

function logMessage($message, $extra = []) {
    if (defined('_SFTPGO_LOG') && _SFTPGO_LOG === true) {
        global $log;

        $log->info($message, $extra);
    }
}