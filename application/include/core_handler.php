<?php
/**
 * Core Handler — Helper functions for config, language, and messages.
 *
 * @author Amin Mahmoudi (MasterkinG)
 * @copyright Copyright (c) 2019 - 2024, MasterkinG32.
 **/

/**
 * Get a configuration value by key.
 *
 * @param string $key
 * @return mixed|null
 */
function get_config($key)
{
    global $config;
    return $config[$key] ?? null;
}

/**
 * Get a core-specific configuration value.
 *
 * @param string $key
 * @return mixed|null
 */
function get_core_config($key)
{
    global $config;
    $coreConfigs = [
        0 => [ // TrinityCore
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
        1 => [ // AzerothCore
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
        2 => [ // AshamaneCore
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
        3 => [ // Skyfire
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
        4 => [ // OregonCore
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
        5 => [ // CMangos
            'salt_field'    => 'salt',
            'verifier_field' => 'verifier',
        ],
    ];

    $core = $config['server_core'] ?? 0;
    return $coreConfigs[$core][$key] ?? ($coreConfigs[0][$key] ?? null);
}

/**
 * Get a translated language string.
 *
 * @param string $key
 * @return string
 */
function lang($key)
{
    global $language;
    return $language[$key] ?? $key;
}

/**
 * Echo a translated language string.
 *
 * @param string $key
 */
function elang($key)
{
    echo htmlspecialchars(lang($key), ENT_QUOTES, 'UTF-8');
}

/**
 * Store or retrieve an error message.
 *
 * @param string|null $msg
 * @return string
 */
function error_msg($msg = null)
{
    static $message = '';
    if ($msg !== null) {
        $message = $msg;
    }
    return $message;
}

/**
 * Store or retrieve a success message.
 *
 * @param string|null $msg
 * @return string
 */
function success_msg($msg = null)
{
    static $message = '';
    if ($msg !== null) {
        $message = $msg;
    }
    return $message;
}
