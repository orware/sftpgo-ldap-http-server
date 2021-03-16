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
            global $connections, $domains_to_strip_automatically, $convert_username_to_lowercase, $username_minimum_length, $username_blacklist;

            // Convert username to lowercase if setting is enabled:
            if (isset($convert_username_to_lowercase) && $convert_username_to_lowercase === true) {
                $beforeUsername = $data['username'];
                $data['username'] = strtolower($data['username']);

                if ($beforeUsername !== $data['username']) {
                    logMessage('Converted ' . $beforeUsername . ' to ' . $data['username']);
                }
            }

            // Strip specific organization email domains if provided:
            if (isset($domains_to_strip_automatically)) {
                logMessage('Username before domain stripping: ' . $data['username']);
                foreach($domains_to_strip_automatically as $domain) {
                    $domain = '@'.str_replace('@', '', $domain);
                    logMessage('Attempting to strip ' . $domain . ' from provided username.');
                    $data['username'] = str_replace($domain, '', $data['username']);
                }
            }

            // Prevent short usernames from being processed:
            if (isset($username_minimum_length) && $username_minimum_length > 0) {
                if (strlen($data['username']) < $username_minimum_length) {
                    logMessage('Denying ' . $data['username'] . ' since length is less than minimum allowed (' . $username_minimum_length . ')');
                    return denyRequest();
                }
            }

            // Prevent blacklisted usernames from being processed:
            if (isset($username_blacklist) && !empty($username_blacklist)) {
                if (array_search($data['username'], $username_blacklist) !== false) {
                    logMessage('Denying ' . $data['username'] . ' since it is in the username blacklist');
                    return denyRequest();
                }
            }

            foreach($connections as $connectionName => $connection) {

                logMessage('Before connection attempt to ' . $connectionName);
                $connection->reconnect();
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

                        logMessage('Disconnecting from ' . $connectionName);
                        $connection->disconnect();
                        return createResponse($output);
                    } else {
                        // Username or password is incorrect.
                        logMessage('After authentication attempt for: ' . $data['username'] . ' (failed!)');

                        logMessage('Disconnecting from ' . $connectionName);
                        $connection->disconnect();
                        return denyRequest();
                    }
                } else {
                   logMessage('Disconnecting from ' . $connectionName);
                   $connection->disconnect();
                }

                logMessage('User lookup failed for: ' . $data['username']);
            }

        } catch (\LdapRecord\Auth\BindException $e) {
            $error = $e->getDetailedError();

            logMessage($error->getErrorMessage());
            //echo $error->getErrorCode();
            //echo $error->getErrorMessage();
            //echo $error->getDiagnosticMessage();
        }
    }

    return denyRequest();
}

function createResponseObject($connectionName, $username) {
    global $home_directories, $virtual_folders, $default_output_object, $connection_output_objects, $user_output_objects;

    $userHomeDirectory = str_replace('#USERNAME#', $username, $home_directories[$connectionName]);

    $output = $default_output_object;

    // Connection-specific output objects override the default one:
    if (isset($connection_output_objects[$connectionName])) {
        logMessage('Using connection-specific output object override.');
        $output = $connection_output_objects[$connectionName];
    }

    // Username-specific output objects override the default and connection-specific ones:
    if (isset($user_output_objects[$username])) {
        logMessage('Using username-specific output object override.');
        $output = $user_output_objects[$username];
    }

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