<?php
/**
 * src/utils.php
 * Utility functions shared by the API: debug_log (gated by the config
 * debug flag), format_error, format_response (the success/message/data
 * envelope every endpoint returns), sanitize_input, validate_params, and
 * validate_token (timing-safe comparison against the configured
 * bearer_token; fails closed when no bearer is configured).
 */

/**
 * Debug logging
 *
 * @param string $message Message to log
 * @param array $config Configuration array
 * @param string $level Log level
 * @return void
 */
function debug_log(string $message, array $config, string $level = 'DEBUG'): void {
    if (empty($config['debug'])) {
        return;
    }

    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [$level] $message");
}

/**
 * Format error message
 *
 * @param string $message Error message
 * @param mixed $context Additional context
 * @return string Formatted error message
 */
function format_error(string $message, $context = null): string {
    if ($context !== null) {
        $context_str = is_array($context) ? json_encode($context) : (string)$context;
        return sprintf("%s: %s", $message, $context_str);
    }
    return $message;
}

/**
 * Format response array
 *
 * @param bool $success Success status
 * @param mixed|null $data Response data
 * @param string $message Response message
 * @param int $status_code HTTP status code
 * @return array Formatted response array
 */
function format_response(bool $success, $data = null, string $message = '', int $status_code = 200): array {
    http_response_code($status_code);
    return [
        'success' => $success,
        'message' => $message,
        'data' => $data
    ];
}

/**
 * Sanitize input string or array
 *
 * @param mixed $input Input to sanitize
 * @return mixed Sanitized input
 */
function sanitize_input($input) {
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate required parameters
 *
 * @param array $data Data to validate
 * @param array $required Required parameter names
 * @return array Validation result with is_valid and missing fields
 */
function validate_params(array $data, array $required): array {
    $missing = [];
    foreach ($required as $param) {
        if (!isset($data[$param]) || empty($data[$param])) {
            $missing[] = $param;
        }
    }
    
    return [
        'is_valid' => empty($missing),
        'missing' => $missing
    ];
}

/**
 * Validate token for API
 *
 * Single-operator static bearer check: timing-safe comparison against the
 * configured bearer_token. Fails closed when no bearer is configured
 * (empty/missing) so a misconfigured deployment never authenticates.
 */
function validate_token($token) {
    global $config; // Use the global config that was already loaded

    $configured = $config['bearer_token'] ?? null;

    // Fail closed: an empty or missing configured bearer rejects everyone.
    if (!is_string($configured) || $configured === '') {
        return false;
    }

    // Fail closed: the presented credential must be a non-empty string.
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($configured, $token);
}
