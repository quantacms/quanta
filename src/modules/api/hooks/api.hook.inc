<?php
namespace Quanta\Common;



/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param $vars
 *   An array of variables.
 */
function api_boot(Environment $env, array $vars) {
    api_check($env, $vars);
}


/**
 * Check if there are any API request.
 */
function api_check($env, $vars) {
    if (strpos($env->request_uri, '/api/') !== false) {
        $response = new \stdClass();
        $full_path = Api::getEndpoint($env);

        // --- Route matching ---
        // Collect routes registered by modules via the 'api_routes' hook.
        // Each route is a pattern string
        // NOTE: Api::getEndpoint() already converts hyphens to underscores,
        // so all pattern segments must also use underscores.
        // Non-placeholder segments form the hook name; placeholders are captured
        // in order into $path_params.
        $route_vars = ['routes' => [], 'full_path' => $full_path];
        $env->hook('api_routes', $route_vars);
        $registered_routes = $route_vars['routes'];

        $path = null;
        $path_params = [];

        foreach ($registered_routes as $pattern) {
            $matched = api_match_route($full_path, $pattern, $captured);
            if ($matched) {
                // Build hook name from non-placeholder segments
                $pattern_parts = explode('/', trim($pattern, '/'));
                $name_parts = [];
                foreach ($pattern_parts as $seg) {
                    if (strpos($seg, '{') === false) {
                        // Api::getEndpoint already converted hyphens -> underscores
                        $name_parts[] = $seg;
                    }
                }
                // Drop leading version segment (e.g. '1') from hook name
                if (!empty($name_parts) && is_numeric($name_parts[0])) {
                    array_shift($name_parts);
                }
                $path = implode('_', $name_parts);
                $path_params = $captured;
                break;
            }
        }

        // --- Fallback: original numeric-strip logic ---
        if ($path === null) {
            $path_parts_raw = explode('/', trim($full_path, '/'));
            $api_parts  = [];
            $path_params = [];
            foreach ($path_parts_raw as $part) {
                if (is_numeric($part)) {
                    $path_params[] = $part;
                } else {
                    $api_parts[] = str_replace('-', '_', $part);
                }
            }
            $path = implode('_', $api_parts);
        }

        $error_code = null;
        $error_message = null;


        // Check the request method
        switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = (array) $env->query_params;
            $env->hook('api_get_' . $path, $vars);
            break;

        case 'POST':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = (array) $_POST;
            $env->hook('api_post_' . $path, $vars);
            break;

        case 'DELETE':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = [];
            parse_str(file_get_contents('php://input'), $vars['data']);
            $env->hook('api_delete_' . $path, $vars);
            break;

        case 'PUT':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = [];
            parse_str(file_get_contents('php://input'), $vars['data']);
            $env->hook('api_put_' . $path, $vars);
            break;

        case 'PATCH':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = [];
            parse_str(file_get_contents('php://input'), $vars['data']);
            $env->hook('api_patch_' . $path, $vars);
            break;

        default:
            $error_code = 'unsupported_request_method';
            $error_message = 'Unsupported request method.';
            break;
        }

        // If no hook handled the request, return 404.
        $response->success = false;
        $response->error_code = $error_code ?? 'api_not_found';
        $response->error_message = $error_message ?? 'This API not found.';
        $response_json = json_encode($response);
        http_response_code(404);
        exit($response_json);
    }
}

/**
 * Match a URL path against a route pattern.
 *
 * Pattern segments wrapped in { } are treated as wildcards whose captured
 * values are collected in $params in the order they appear.
 *
 * Example:
 *   pattern: '1/{product_id}/pricing-categories'
 *   path:    '1/tour-abc/pricing-categories'
 *   → true, $params = ['tour-abc']
 *
 * @param string $path     The actual URL path (from Api::getEndpoint).
 * @param string $pattern  The route pattern.
 * @param array  $params   OUT – captured placeholder values, in order.
 * @return bool
 */
function api_match_route($path, $pattern, &$params) {
    $params = [];
    $path_segs    = explode('/', trim($path, '/'));
    $pattern_segs = explode('/', trim($pattern, '/'));

    if (count($path_segs) !== count($pattern_segs)) {
        return false;
    }

    foreach ($pattern_segs as $i => $seg) {
        if (strpos($seg, '{') === 0 && substr($seg, -1) === '}') {
            // Wildcard – capture value
            $params[] = $path_segs[$i];
        } elseif ($path_segs[$i] !== $seg) {
            return false;
        }
    }
    return true;
}

function api_generate_encrypted_secret($env, $vars){
    // Generate a random binary string of 32 bytes (256 bits)
    $length = 32; // 32 bytes = 256 bits
    $random_bytes = random_bytes($length);

    // Convert the binary string to a hexadecimal representation
    $secret_key = bin2hex($random_bytes);

    // Define the encryption method and key for transmission
    $method = "AES-256-CBC";
    $transmission_key = $env->getData('TRANSMISSION_KEY');

    // Generate a random initialization vector (IV)
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

    // Encrypt the secret key
    $encrypted_secret = openssl_encrypt($secret_key, $method, $transmission_key, 0, $iv);

    // Concatenate the IV and the encrypted secret
    $encrypted_secret = base64_encode($iv . $encrypted_secret);

    return $encrypted_secret;
}

function api_decrypt_secret($env, &$vars){
    // The transmission key used for decryption
    $transmission_key = $env->getData('TRANSMISSION_KEY');

    // The encrypted secret received from the client
    $received_encrypted_secret = $vars['encrypted_secret'];

    // Decode the encrypted secret
    $encrypted_secret = base64_decode($received_encrypted_secret);

    // Extract the IV and the encrypted data
    $method = "AES-256-CBC";
    $iv_length = openssl_cipher_iv_length($method);
    $iv = substr($encrypted_secret, 0, $iv_length);
    $encrypted_secret = substr($encrypted_secret, $iv_length);

    // Decrypt the secret key
    $decrypted_secret = openssl_decrypt($encrypted_secret, $method, $transmission_key, 0, $iv);

    // Use the decrypted secret key
    if ($decrypted_secret === false) {
        return null;
    } else {
        return $decrypted_secret;
    }
}
