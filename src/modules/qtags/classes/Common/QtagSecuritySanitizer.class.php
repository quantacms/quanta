<?php
namespace Quanta\Common;

/**
 * Class QtagSecuritySanitizer
 * Centralized security sanitizer and scope guard for Quanta Qtags (#191).
 */
class QtagSecuritySanitizer {
  /**
   * Patterns of environment/session keys that must never be exposed via Qtags.
   */
  private const SENSITIVE_KEY_PATTERNS = [
    '/pass(word)?/i',
    '/secret/i',
    '/token/i',
    '/private/i',
    '/api[_-]?key/i',
    '/db_/i',
    '/auth/i',
  ];

  /**
   * Sanitizes a file target to prevent path traversal (../) outside a node folder.
   */
  public static function sanitizeFileName(string $target): string {
    $cleaned = str_replace(["\0", "\\"], ['', '/'], $target);
    return basename($cleaned);
  }

  /**
   * Verifies that a resolved filesystem path remains strictly inside $allowedRootDir.
   */
  public static function isPathWithinScope(string $candidatePath, string $allowedRootDir): bool {
    $realRoot = realpath($allowedRootDir);
    $realCandidate = realpath($candidatePath);
    if ($realRoot === FALSE || $realCandidate === FALSE) {
      return FALSE;
    }
    $normalizedRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strpos($realCandidate . DIRECTORY_SEPARATOR, $normalizedRoot) === 0;
  }

  /**
   * Checks whether an environment or session key is safe to expose in Qtag output.
   */
  public static function isSafeEnvOrSessionKey(string $key): bool {
    foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
      if (preg_match($pattern, $key)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Escapes a Qtag attribute or target value for safe HTML rendering (prevents XSS).
   */
  public static function escapeHtml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Validates redirect targets to prevent Open Redirect attacks.
   */
  public static function sanitizeRedirectUrl(string $url): string {
    $trimmed = trim($url);
    if (strpos($trimmed, '/') === 0 && strpos($trimmed, '//') !== 0) {
      return $trimmed;
    }
    return '/';
  }
}
