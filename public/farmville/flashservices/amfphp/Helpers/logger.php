<?php

class Logger
{
    private static $logFile = null;
    private static $buffer = [];
    private static $initialized = false;
    private static $enabled = null;
    private static $traceUids = null;

    private static function envValue($key)
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        $envFile = dirname(AMFPHP_ROOTPATH, 4) . '/.env';
        if (file_exists($envFile)) {
            $contents = file_get_contents($envFile);
            if (preg_match('/^' . preg_quote($key, '/') . '\\s*=\\s*(.+)$/m', $contents, $matches)) {
                return trim($matches[1], "\"' \t\n\r");
            }
        }

        return null;
    }

    
    private static function isEnabled()
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        self::$enabled = true;

        $value = self::envValue('FARMVILLE_LOG_ENABLED');
        if ($value !== null) {
            self::$enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return self::$enabled;
    }

    /**
     * Initialize the logger before the AMF gateway runs so fatal-request
     * diagnostics can still be flushed from a shutdown handler.
     */
    public static function initialize()
    {
        self::init();
    }

    public static function requestId()
    {
        if (!isset($GLOBALS['_amf_request_id'])) {
            try {
                $GLOBALS['_amf_request_id'] = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                $GLOBALS['_amf_request_id'] = substr(sha1(uniqid('', true)), 0, 16);
            }
        }

        return $GLOBALS['_amf_request_id'];
    }

    /**
     * Return whether the temporary AMF trace is enabled for this player.
     * Configure FARMVILLE_AMF_TRACE_UIDS as a comma-separated UID list, or
     * use '*' for all users. An unset value keeps the trace disabled.
     */
    public static function isTraceEnabledForUid($uid)
    {
        if (self::$traceUids === null) {
            $value = self::envValue('FARMVILLE_AMF_TRACE_UIDS');
            if ($value === null || trim($value) === '') {
                self::$traceUids = [];
            } else {
                self::$traceUids = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
            }
        }

        return in_array('*', self::$traceUids, true)
            || in_array((string) $uid, array_map('strval', self::$traceUids), true);
    }

    /**
     * Write a user-scoped AMF diagnostic entry without enabling the trace for
     * other players or logging the raw request payload.
     */
    public static function trace($uid, $message, $data = [])
    {
        if (!self::isTraceEnabledForUid($uid)) {
            return;
        }

        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        $data = array_merge([
            'request_id' => self::requestId(),
            'uid' => (string) $uid,
        ], $data);

        self::debug('AMFTrace', $message, $data);
    }

    
    private static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        if (!self::isEnabled()) {
            return;
        }

        self::$logFile = dirname(AMFPHP_ROOTPATH, 4) . '/storage/logs/farmville.log';

        $dir = dirname(self::$logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        register_shutdown_function([self::class, 'flush']);
    }

    
    public static function log($category, $message, $data = null)
    {
        self::init();
        if (!self::$enabled) return;

        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$category] $message";

        if ($data !== null) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            $entry .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        self::$buffer[] = $entry;
    }

    
    public static function section($category, $title)
    {
        self::init();
        if (!self::$enabled) return;

        $timestamp = date('Y-m-d H:i:s');
        self::$buffer[] = "\n[$timestamp] [$category] === $title ===";
    }

    
    public static function error($category, $message, $data = null)
    {
        self::init();
        if (!self::$enabled) return;

        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$category] ERROR: $message";

        if ($data !== null) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            $entry .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        self::$buffer[] = $entry;
    }

    /**
     * Keep warning-level call sites compatible with this lightweight logger.
     * Older handlers already use Logger::warning(), so an absent method turns
     * an otherwise recoverable validation/canonicalization path into a failed
     * persistence transaction.
     */
    public static function warning($category, $message, $data = null)
    {
        self::init();
        if (!self::$enabled) return;

        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$category] WARNING: $message";

        if ($data !== null) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            $entry .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        self::$buffer[] = $entry;
    }

    
    public static function debug($category, $message, $data = null)
    {
        self::init();
        if (!self::$enabled) return;

        $timestamp = date('Y-m-d H:i:s');
        $entry = "[$timestamp] [$category]   $message";

        if ($data !== null) {
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            $entry .= " " . json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        self::$buffer[] = $entry;
    }

    
    public static function flush()
    {
        if (empty(self::$buffer) || self::$logFile === null) {
            return;
        }

        $content = implode("\n", self::$buffer) . "\n";
        file_put_contents(self::$logFile, $content, FILE_APPEND | LOCK_EX);
        self::$buffer = [];
    }
}
