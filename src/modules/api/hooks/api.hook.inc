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
        $path_parts = explode('/', $full_path);
        
        $api_parts = array();
        $path_params = array();
        
        foreach ($path_parts as $part) {
            if (is_numeric($part)) {
                $path_params[] = $part;
            } else {
                $api_parts[] = $part;
            }
        }
        
        // The endpoint name is formed by concatenating non-numeric parts
        $path = implode('_', $api_parts);
        
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
            $vars['data'] = (array) $_DELETE;
            $env->hook('api_delete_' . $path, $vars);
            break;
        
        case 'PUT':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = (array) $_PUT;
            $env->hook('api_put_' . $path, $vars);
            break;
        
        case 'PATCH':
            $vars['api'] = $path;
            $vars['path_parts'] = $path_params;
            $vars['data'] = (array) $_PATCH;
            $env->hook('api_patch_' . $path, $vars);
            break;

        default:
            $error_code = 'unsupported_request_method';
            $error_message = 'Unsupported request method.';
            break;

        }
    // If no function returns a response, this means that this function does not exist
    $response->success = false;
    $response->error_code = $error_code ? $error_code : 'api_not_found';
    $response->error_message = $error_message ? $error_message : 'This API not found.';
    $response_json = json_encode($response);
    http_response_code(404);
    exit($response_json);  
    } 
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
