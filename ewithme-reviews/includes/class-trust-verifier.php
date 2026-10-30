<?php
/**
 * Standalone Reference Verifier for Ewithme Trust Protocol v2.
 * Validates canonical RFC 8785 JSON formatting, Ed25519 signatures via libsodium,
 * and review text commitments.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ewithme_Trust_Verifier {

    const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Serializes any value into RFC 8785 canonical JSON string.
     * Recursively sorts object keys lexicographically by UTF-16 code units.
     *
     * @param mixed $data
     * @return string
     */
    public static function canonicalize_json($data) {
        if (is_null($data)) {
            return 'null';
        }
        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }
        if (is_int($data) || is_float($data)) {
            return strval($data);
        }
        if (is_string($data)) {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($data)) {
            // Check if associative array or sequential array
            $is_assoc = false;
            $i = 0;
            foreach ($data as $k => $v) {
                if ($k !== $i++) {
                    $is_assoc = true;
                    break;
                }
            }

            if (!$is_assoc) {
                $items = array();
                foreach ($data as $item) {
                    $items[] = self::canonicalize_json($item);
                }
                return '[' . implode(',', $items) . ']';
            } else {
                ksort($data, SORT_STRING);
                $props = array();
                foreach ($data as $k => $v) {
                    $props[] = json_encode(strval($k), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . self::canonicalize_json($v);
                }
                return '{' . implode(',', $props) . '}';
            }
        }

        return json_encode($data);
    }

    /**
     * Computes the SHA-256 entry hash of an audit log entry.
     *
     * @param array $entry
     * @return string 64-char hex
     */
    public static function compute_entry_hash($entry) {
        $canonical = self::canonicalize_json($entry);
        return hash('sha256', $canonical);
    }

    /**
     * Verifies an Ed25519 detached signature over a message using libsodium.
     *
     * @param string $message - Binary or string message (entry hash)
     * @param string $signature_hex - 64-byte signature in hex
     * @param string $public_key_hex - 32-byte raw Ed25519 public key in hex
     * @return bool
     */
    public static function verify_signature($message, $signature_hex, $public_key_hex) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            // libsodium not available in PHP runtime
            return false;
        }

        $sig = hex2bin($signature_hex);
        $pub = hex2bin($public_key_hex);

        if (strlen($sig) !== 64 || strlen($pub) !== 32) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, $message, $pub);
    }

    /**
     * Verifies a Protocol v2 entry payload, hash, and Ed25519 signature.
     *
     * @param array $entry
     * @param string $signature_hex
     * @param string $public_key_hex
     * @return array [ 'valid' => bool, 'entry_hash' => string, 'error' => string ]
     */
    public static function verify_review_entry($entry, $signature_hex, $public_key_hex) {
        if (!is_array($entry) || empty($entry['v']) || intval($entry['v']) !== 2) {
            return array('valid' => false, 'error' => 'Invalid or unsupported protocol version');
        }

        $computed_hash = self::compute_entry_hash($entry);

        if (!self::verify_signature($computed_hash, $signature_hex, $public_key_hex)) {
            return array('valid' => false, 'entry_hash' => $computed_hash, 'error' => 'Signature verification failed');
        }

        return array('valid' => true, 'entry_hash' => $computed_hash);
    }

    /**
     * Verifies a text commitment: SHA-256(salt:text) === commitment.
     *
     * @param string $text
     * @param string $salt
     * @param string $commitment_hex
     * @return bool
     */
    public static function verify_text_commitment($text, $salt, $commitment_hex) {
        $normalized = str_replace("\r\n", "\n", trim($text));
        $computed = hash('sha256', $salt . ':' . $normalized);
        return strcasecmp($computed, $commitment_hex) === 0;
    }
}
