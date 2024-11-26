<?php

spl_autoload_register(function ($class) {
    // Define the namespaces and their corresponding directories
    $namespaces = [
        'FuseWP\\Core\\' => __DIR__ . '/src/',
        'Authifly\\Provider\\' => __DIR__ . '/third-party-authifly/authifly/',
        // needed for Authifly\Storage\OAuthCredentialStorage.php located in authifly root folder
        'Authifly\\Storage\\' => __DIR__ . '/third-party-authifly/authifly/',
        'Authifly\\' => __DIR__ . '/third-party-authifly/authifly/src/',
    ];

    foreach ($namespaces as $prefix => $base_dir) {
        // Does the class use this namespace prefix?
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue; // Move to the next namespace
        }

        // Get the relative class name
        $relative_class = substr($class, $len);

        // Replace namespace separators with directory separators,
        // append with .php
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // If the file exists, require it
        if (file_exists($file)) {
            require $file;
            return; // Class found, so we're done
        }
    }
});

// Include any additional required files
require __DIR__ . "/src/Functions/CustomSettingsPageApi.php";
require __DIR__ . "/src/Functions/functions.php";
require __DIR__ . "/src/Functions/Shogun.php";