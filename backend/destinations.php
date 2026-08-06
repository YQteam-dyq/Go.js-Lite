<?php

// Backup destinations: S3/FTP/SFTP destination management and remote operations.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_unseal_secret($sealed) {
    if ($sealed === null || $sealed === '' || $sealed === '****') return false;
    return gojs_decrypt((string)$sealed);
}

function gojs_destinations_load(): array {
    global $config;
    return isset($config['backup_destinations']) && is_array($config['backup_destinations'])
        ? $config['backup_destinations']
        : array();
}

function gojs_destinations_save(array $destinations): void {
    global $config;
    $config['backup_destinations'] = $destinations;
    gojs_save_config();
}

function gojs_destination_redact(array $dest): array {
    $redacted = $dest;
    $secret_fields = array('secret_key_enc', 'password_enc', 'private_key_enc');
    foreach ($secret_fields as $f) {
        if (isset($redacted[$f]) && $redacted[$f] !== '') {
            $redacted[$f] = '****';
        }
    }
    return $redacted;
}

class GOJS_S3_Destination {
    private $access_key;
    private $secret_key;
    private $endpoint;
    private $region;
    private $bucket;
    private $sse;
    private $path_prefix;

    public static function available(): bool {
        $allow_fopen = ini_get('allow_url_fopen') === '1' || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
        $has_hash = function_exists('hash_hmac') || function_exists('openssl_hmac');
        return $allow_fopen && (extension_loaded('openssl') || $has_hash);
    }

    public function __construct($config) {
        $this->access_key = isset($config['access_key']) ? (string)$config['access_key'] : '';
        $this->secret_key = isset($config['secret_key']) ? (string)$config['secret_key'] : '';
        $this->endpoint = isset($config['endpoint']) && $config['endpoint'] !== '' ? rtrim((string)$config['endpoint'], '/') : 'https://s3.amazonaws.com';
        $this->region = isset($config['region']) && $config['region'] !== '' ? (string)$config['region'] : 'us-east-1';
        $this->bucket = isset($config['bucket']) ? (string)$config['bucket'] : '';
        $this->sse = !empty($config['sse']);
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
    }

    private function sign($key, $data) {
        if (function_exists('hash_hmac')) {
            return hash_hmac('sha256', $data, $key, true);
        }
        return openssl_hmac($data, $key, 'sha256');
    }

    private function sha256($data) {
        if (function_exists('hash')) {
            return hash('sha256', $data);
        }
        return bin2hex(openssl_digest($data, 'sha256'));
    }

    public function v4($service, $http_method, $path, $headers = array(), $query = array(), $payload = '') {
        $t = time();
        $datestamp = gmdate('Ymd', $t);
        $amzdate = gmdate('Ymd\\THis\\Z', $t);

        $parsed = parse_url($this->endpoint);
        $host = isset($parsed['host']) ? $parsed['host'] : 's3.amazonaws.com';
        if (isset($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        $canonical_headers = array('host' => $host);
        foreach ($headers as $k => $v) {
            $canonical_headers[strtolower($k)] = trim($v);
        }
        $canonical_headers['x-amz-date'] = $amzdate;

        ksort($canonical_headers);
        $signed_headers = implode(';', array_keys($canonical_headers));

        $canonical_header_str = '';
        foreach ($canonical_headers as $k => $v) {
            $canonical_header_str .= $k . ':' . $v . "\n";
        }

        ksort($query);
        $query_str = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $payload_hash = $this->sha256($payload);

        $canonical_request = $http_method . "\n"
            . $path . "\n"
            . $query_str . "\n"
            . $canonical_header_str . "\n"
            . $signed_headers . "\n"
            . $payload_hash;

        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $datestamp . '/' . $this->region . '/' . $service . '/aws4_request';

        $string_to_sign = $algorithm . "\n"
            . $amzdate . "\n"
            . $credential_scope . "\n"
            . $this->sha256($canonical_request);

        $k_date = $this->sign('AWS4' . $this->secret_key, $datestamp);
        $k_region = $this->sign($k_date, $this->region);
        $k_service = $this->sign($k_region, $service);
        $k_signing = $this->sign($k_service, 'aws4_request');
        $signature = bin2hex($this->sign($k_signing, $string_to_sign));

        return $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;
    }

    private function request($method, $key, $body = '', $extra_headers = array(), $query = array()) {
        $service = 's3';
        $path = '/' . $this->bucket . '/' . ltrim($key, '/');
        $real_path = '/' . $this->bucket . '/' . ltrim($key, '/');

        $headers = $extra_headers;
        if ($body !== '' && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string)strlen($body);
        }
        if ($this->sse) {
            $headers['x-amz-server-side-encryption'] = 'AES256';
        }

        $auth = $this->v4($service, $method, $real_path, $headers, $query, $body);

        $parsed = parse_url($this->endpoint);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = isset($parsed['host']) ? $parsed['host'] : 's3.amazonaws.com';
        $port = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);

        $url_path = $path;
        if (!empty($query)) {
            $url_path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $req_headers = array(
            'Host' => $host,
            'X-Amz-Date' => gmdate('Ymd\\THis\\Z'),
            'Authorization' => $auth,
        );
        foreach ($headers as $k => $v) {
            $req_headers[$k] = $v;
        }

        $ctx_headers = '';
        foreach ($req_headers as $k => $v) {
            $ctx_headers .= $k . ': ' . $v . "\r\n";
        }

        $opts = array(
            'http' => array(
                'method' => $method,
                'header' => $ctx_headers,
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ),
        );

        if ($scheme === 'https' && extension_loaded('openssl')) {
            $opts['ssl'] = array(
                'verify_peer' => true,
                'verify_peer_name' => true,
            );
        }

        $context = stream_context_create($opts);
        $url = $scheme . '://' . $host . ($port !== 80 && $port !== 443 ? ':' . $port : '') . $url_path;

        $result = @file_get_contents($url, false, $context);
        $status = 0;
        $resp_headers = array();
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\\/\\S+\\s+(\\d+)/', $h, $m)) {
                    $status = (int)$m[1];
                } elseif (strpos($h, ':') !== false) {
                    list($k, $v) = explode(':', $h, 2);
                    $resp_headers[strtolower(trim($k))] = trim($v);
                }
            }
        }

        return array('status' => $status, 'body' => $result === false ? '' : $result, 'headers' => $resp_headers);
    }

    private function full_key($key) {
        $parts = array();
        if ($this->path_prefix !== '') $parts[] = $this->path_prefix;
        $parts[] = ltrim($key, '/');
        return implode('/', array_filter($parts, 'strlen'));
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 's3_not_available', 'error_key' => 's3_requirements_missing');
        }
        if ($this->access_key === '' || $this->secret_key === '' || $this->bucket === '') {
            return array('ok' => false, 'error' => 's3_missing_credentials', 'error_key' => 's3_missing_credentials');
        }

        $head = $this->request('HEAD', '');
        if ($head['status'] !== 200 && $head['status'] !== 403 && $head['status'] !== 404) {
            return array(
                'ok' => false,
                'error' => 's3_head_bucket_failed: HTTP ' . $head['status'] . ' ' . substr($head['body'], 0, 200),
                'error_key' => 's3_connect_failed',
            );
        }

        $test_key = $this->full_key('gojs-test-' . uniqid('', true) . '.txt');
        $test_content = 'gojs-connection-test-' . time();

        $put = $this->request('PUT', $test_key, $test_content, array('Content-Type' => 'text/plain'));
        if ($put['status'] !== 200) {
            return array(
                'ok' => false,
                'error' => 's3_put_failed: HTTP ' . $put['status'] . ' ' . substr($put['body'], 0, 200),
                'error_key' => 's3_bucket_not_writable',
            );
        }

        $this->request('DELETE', $test_key);

        return array(
            'ok' => true,
            'bucket_writable' => true,
            'prefix' => $this->path_prefix,
        );
    }

    public function putObject(string $key, $body): array {
        $full = $this->full_key($key);
        if (is_resource($body)) {
            $content = stream_get_contents($body);
        } else {
            $content = (string)$body;
        }
        $resp = $this->request('PUT', $full, $content, array('Content-Type' => 'application/octet-stream'));
        if ($resp['status'] === 200) {
            return array('ok' => true);
        }
        return array('ok' => false, 'error' => 'HTTP ' . $resp['status'] . ' ' . substr($resp['body'], 0, 200));
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $full_prefix = $this->full_key($prefix);
        $query = array(
            'list-type' => '2',
            'prefix' => $full_prefix,
            'max-keys' => (string)min($max, 1000),
        );
        $resp = $this->request('GET', '', '', array(), $query);
        $objects = array();
        if ($resp['status'] === 200 && $resp['body'] !== '') {
            $xml = @simplexml_load_string($resp['body']);
            if ($xml && isset($xml->Contents)) {
                foreach ($xml->Contents as $c) {
                    $objects[] = array(
                        'key' => (string)$c->Key,
                        'size' => (int)$c->Size,
                        'last_modified' => (string)$c->LastModified,
                    );
                }
            }
        }
        return $objects;
    }

    public function deleteObject(string $key): array {
        $full = $this->full_key($key);
        $resp = $this->request('DELETE', $full);
        if ($resp['status'] === 204 || $resp['status'] === 200) {
            return array('ok' => true);
        }
        return array('ok' => false, 'error' => 'HTTP ' . $resp['status']);
    }
}

class GOJS_FTP_Destination {
    private $host;
    private $port;
    private $username;
    private $password;
    private $path_prefix;
    private $use_tls;

    public static function available(): bool {
        return extension_loaded('ftp') || function_exists('fsockopen');
    }

    public function __construct($config) {
        $this->host = isset($config['host']) ? (string)$config['host'] : '';
        $this->port = isset($config['port']) ? (int)$config['port'] : 21;
        $this->username = isset($config['username']) ? (string)$config['username'] : '';
        $this->password = isset($config['password']) ? (string)$config['password'] : '';
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
        $this->use_tls = !empty($config['use_tls']);
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 'ftp_not_available', 'error_key' => 'ftp_requirements_missing');
        }
        if ($this->host === '' || $this->username === '') {
            return array('ok' => false, 'error' => 'ftp_missing_params', 'error_key' => 'ftp_missing_credentials');
        }

        try {
            if (extension_loaded('ftp')) {
                return $this->test_ext();
            }
            return $this->test_fsock();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'ftp_connect_failed');
        }
    }

    private function test_ext(): array {
        $conn = $this->use_tls ? @ftp_ssl_connect($this->host, $this->port, 15) : @ftp_connect($this->host, $this->port, 15);
        if (!$conn) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        if (!@ftp_login($conn, $this->username, $this->password)) {
            @ftp_close($conn);
            return array('ok' => false, 'error' => 'ftp_login_failed', 'error_key' => 'ftp_login_failed');
        }
        @ftp_pasv($conn, true);

        if ($this->path_prefix !== '') {
            if (!@ftp_chdir($conn, $this->path_prefix)) {
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_cd_failed', 'error_key' => 'ftp_path_inaccessible');
            }
        }

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_ftp_test_');
        @file_put_contents($tmp_file, $content);

        if (!@ftp_put($conn, $tmp, $tmp_file, FTP_BINARY)) {
            @unlink($tmp_file);
            @ftp_close($conn);
            return array('ok' => false, 'error' => 'ftp_put_failed', 'error_key' => 'ftp_not_writable');
        }
        @unlink($tmp_file);

        $list = @ftp_nlist($conn, '.');
        @ftp_delete($conn, $tmp);
        @ftp_close($conn);

        return array('ok' => true);
    }

    private function test_fsock(): array {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 15);
        if (!$fp) {
            return array('ok' => false, 'error' => "fsock_connect: $errstr ($errno)", 'error_key' => 'ftp_connect_failed');
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function ($cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };
        $code = function ($resp) {
            return (int)substr(trim($resp), 0, 3);
        };

        $banner = $read();
        if ($code($banner) !== 220) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_banner_invalid', 'error_key' => 'ftp_connect_failed');
        }

        $write('USER ' . $this->username);
        $r = $read();
        if ($code($r) === 331) {
            $write('PASS ' . $this->password);
            $r = $read();
        }
        if ($code($r) !== 230) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_login_failed', 'error_key' => 'ftp_login_failed');
        }

        if ($this->path_prefix !== '') {
            $write('CWD ' . $this->path_prefix);
            $r = $read();
            if ($code($r) !== 250) {
                fclose($fp);
                return array('ok' => false, 'error' => 'ftp_cd_failed', 'error_key' => 'ftp_path_inaccessible');
            }
        }

        $write('PASV');
        $r = $read();
        if ($code($r) !== 227) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        if (!preg_match('/\\((\\d+),(\\d+),(\\d+),(\\d+),(\\d+),(\\d+)\\)/', $r, $m)) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_pasv_parse_failed');
        }
        $dhost = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $dport = ((int)$m[5] << 8) | (int)$m[6];

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();

        $write('STOR ' . $tmp);
        $r = $read();
        if ($code($r) !== 150 && $code($r) !== 125) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_stor_failed', 'error_key' => 'ftp_not_writable');
        }

        $dfp = @fsockopen($dhost, $dport, $errno, $errstr, 10);
        if (!$dfp) {
            fclose($fp);
            return array('ok' => false, 'error' => "ftp_data_connect: $errstr ($errno)");
        }
        fwrite($dfp, $content);
        fclose($dfp);
        $r = $read();

        $write('DELE ' . $tmp);
        $read();
        $write('QUIT');
        $read();
        fclose($fp);

        return array('ok' => true);
    }

    /* ---------- Remote file operations (Task 3: browse / download loop) ----------
     * Key semantics match gojs_backup_execute_schedule / gojs_retention_prune:
     * the incoming key is the full remote key relative to the FTP server root (includes path_prefix).
     * listObjects returns a plain array [{key,size,last_modified}].
     */

    // Establish an FTP connection (ext path): login + passive mode, without changing directory.
    private function connect_ext() {
        if (!extension_loaded('ftp')) return null;
        $conn = $this->use_tls ? @ftp_ssl_connect($this->host, $this->port, 15) : @ftp_connect($this->host, $this->port, 15);
        if (!$conn) return null;
        if (!@ftp_login($conn, $this->username, $this->password)) {
            @ftp_close($conn);
            return null;
        }
        @ftp_pasv($conn, true);
        return $conn;
    }

    // Establish an FTP control connection (fsock path); returns array('fp'=>..., 'read'=>..., 'write'=>..., 'code'=>...).
    private function connect_fsock() {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 15);
        if (!$fp) return null;
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function ($cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };
        $code = function ($resp) {
            return (int)substr(trim($resp), 0, 3);
        };

        $banner = $read();
        if ($code($banner) !== 220) {
            fclose($fp);
            return null;
        }

        $write('USER ' . $this->username);
        $r = $read();
        if ($code($r) === 331) {
            $write('PASS ' . $this->password);
            $r = $read();
        }
        if ($code($r) !== 230) {
            fclose($fp);
            return null;
        }

        return array('fp' => $fp, 'read' => $read, 'write' => $write, 'code' => $code);
    }

    // fsock passive-mode data connection.
    private function fsock_data_conn($wrap) {
        $wrap['write']('PASV');
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 227) return null;
        if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $r, $m)) return null;
        $dhost = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $dport = ((int)$m[5] << 8) | (int)$m[6];
        $dfp = @fsockopen($dhost, $dport, $errno, $errstr, 10);
        if (!$dfp) return null;
        stream_set_timeout($dfp, 15);
        return $dfp;
    }

    // Close the fsock control connection.
    private function fsock_close($wrap) {
        if (!$wrap || !is_resource($wrap['fp'])) return;
        @$wrap['write']('QUIT');
        @$wrap['read']();
        @fclose($wrap['fp']);
    }

    // Parse one rawlist line (UNIX / DOS); size defaults to 0 and last_modified to '' when unparsable.
    private function parse_rawlist_line($line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^[bcdlps-][rwxst-]{9}\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w{3})\s+(\d{1,2})\s+([\d:]{4,5}|\d{4})\s+(.+)$/', $line, $m)) {
            $name = trim($m[5]);
            if ($name === '.' || $name === '..') return null;
            $ts = strtotime($m[2] . ' ' . $m[3] . ' ' . $m[4]);
            return array(
                'key' => $name,
                'size' => (int)$m[1],
                'last_modified' => $ts !== false && $ts > 0 ? gmdate('c', $ts) : '',
            );
        }
        if (preg_match('/^(\d{2}-\d{2}-\d{2})\s+(\d{2}:\d{2}[AP]M)\s+(.+)$/', $line, $m)) {
            $rest = trim($m[3]);
            $size = 0;
            $name = $rest;
            if (strtoupper(substr($rest, 0, 5)) === '<DIR>') {
                $rest2 = trim(substr($rest, 5));
                if ($rest2 !== '') $name = $rest2;
            } elseif (preg_match('/^(\d+)\s+(.+)$/', $rest, $m2)) {
                $size = (int)$m2[1];
                $name = trim($m2[2]);
            }
            if ($name === '.' || $name === '..') return null;
            $ts = strtotime($m[1] . ' ' . $m[2]);
            return array(
                'key' => $name,
                'size' => $size,
                'last_modified' => $ts !== false && $ts > 0 ? gmdate('c', $ts) : '',
            );
        }
        return null;
    }

    // Normalize an entry key: keys without a directory part are completed to the full key.
    private function normalize_list_key($key, $prefix) {
        $key = trim($key);
        if ($key === '' || $key === '.' || $key === '..') return '';
        if (strpos($key, '/') !== false) return $key;
        $p = rtrim($prefix, '/');
        return $p !== '' ? $p . '/' . $key : $key;
    }

    public function putObject(string $key, $body): array {
        $content = is_resource($body) ? stream_get_contents($body) : (string)$body;
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_put_');
            if (@file_put_contents($tmp_file, $content) === false) {
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_tmp_write_failed');
            }
            $ok = @ftp_put($conn, $key, $tmp_file, FTP_BINARY);
            @unlink($tmp_file);
            @ftp_close($conn);
            if (!$ok) {
                return array('ok' => false, 'error' => 'ftp_put_failed', 'error_key' => 'ftp_not_writable');
            }
            return array('ok' => true);
        }

        // fsock path: PASV + STOR.
        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        $wrap['write']('STOR ' . $key);
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_stor_failed', 'error_key' => 'ftp_not_writable');
        }
        foreach (str_split($content, 8192) as $chunk) {
            fwrite($dfp, $chunk);
        }
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        return array('ok' => true);
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $items = array();
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) return $items;
            $raw = @ftp_rawlist($conn, $prefix !== '' ? $prefix : '.');
            @ftp_close($conn);
            if (!is_array($raw)) return $items;
            foreach ($raw as $line) {
                $parsed = $this->parse_rawlist_line($line);
                if (!$parsed) continue;
                $key = $this->normalize_list_key($parsed['key'], $prefix);
                if ($key === '') continue;
                $items[] = array('key' => $key, 'size' => $parsed['size'], 'last_modified' => $parsed['last_modified']);
                if (count($items) >= $max) break;
            }
            return $items;
        }

        // fsock path: PASV + NLST.
        $wrap = $this->connect_fsock();
        if (!$wrap) return $items;
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return $items;
        }
        $wrap['write']('NLST ' . ($prefix !== '' ? $prefix : '.'));
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return $items;
        }
        $data = '';
        while (!feof($dfp)) {
            $data .= fread($dfp, 8192);
        }
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        foreach (preg_split('/\r\n|\r|\n/', trim($data)) as $n) {
            if ($n === '') continue;
            $key = $this->normalize_list_key($n, $prefix);
            if ($key === '') continue;
            $items[] = array('key' => $key, 'size' => 0, 'last_modified' => '');
            if (count($items) >= $max) break;
        }
        return $items;
    }

    public function deleteObject(string $key): array {
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $ok = @ftp_delete($conn, $key);
            @ftp_close($conn);
            if (!$ok) {
                return array('ok' => false, 'error' => 'ftp_delete_failed');
            }
            return array('ok' => true);
        }

        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $wrap['write']('DELE ' . $key);
        $r = $wrap['read']();
        $ok = $wrap['code']($r) === 250;
        $this->fsock_close($wrap);
        if (!$ok) {
            return array('ok' => false, 'error' => 'ftp_delete_failed');
        }
        return array('ok' => true);
    }

    public function getObject(string $key): array {
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
            if (!@ftp_get($conn, $tmp_file, $key, FTP_BINARY)) {
                @unlink($tmp_file);
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_get_failed', 'error_key' => 'ftp_get_failed');
            }
            @ftp_close($conn);
            return array('ok' => true, 'tmp_path' => $tmp_file);
        }

        // fsock path: PASV + RETR.
        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        $wrap['write']('RETR ' . $key);
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_get_failed', 'error_key' => 'ftp_get_failed');
        }
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
        $out = fopen($tmp_file, 'wb');
        while (!feof($dfp)) {
            $chunk = fread($dfp, 8192);
            if ($chunk === false || $chunk === '') break;
            fwrite($out, $chunk);
        }
        fclose($out);
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        return array('ok' => true, 'tmp_path' => $tmp_file);
    }
}

class GOJS_SFTP_Destination {
    private $host;
    private $port;
    private $username;
    private $password;
    private $private_key;
    private $path_prefix;

    public static function available(): bool {
        return extension_loaded('ssh2') || function_exists('fsockopen');
    }

    public function __construct($config) {
        $this->host = isset($config['host']) ? (string)$config['host'] : '';
        $this->port = isset($config['port']) ? (int)$config['port'] : 22;
        $this->username = isset($config['username']) ? (string)$config['username'] : '';
        $this->password = isset($config['password']) ? (string)$config['password'] : '';
        $this->private_key = isset($config['private_key']) ? (string)$config['private_key'] : '';
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 'sftp_not_available', 'error_key' => 'sftp_requirements_missing');
        }
        if ($this->host === '' || $this->username === '') {
            return array('ok' => false, 'error' => 'sftp_missing_params', 'error_key' => 'sftp_missing_credentials');
        }

        if (!extension_loaded('ssh2')) {
            return array('ok' => false, 'error' => 'ssh2_extension_required', 'error_key' => 'sftp_ssh2_required');
        }

        try {
            return $this->test_ssh2();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'sftp_connect_failed');
        }
    }

    private function test_ssh2(): array {
        $conn = @ssh2_connect($this->host, $this->port);
        if (!$conn) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }

        $authed = false;
        if ($this->private_key !== '') {
            $tmp_pub = '';
            $tmp_key = tempnam(sys_get_temp_dir(), 'gojs_sftp_key_');
            $tmp_pub_file = $tmp_key . '.pub';
            @file_put_contents($tmp_key, $this->private_key);
            @chmod($tmp_key, 0600);

            $lines = explode("\n", trim($this->private_key));
            if (strpos($lines[0] ?? '', 'PRIVATE KEY') !== false) {
                $openssl_available = function_exists('openssl_pkey_get_private');
                if ($openssl_available) {
                    $pkey = @openssl_pkey_get_private($this->private_key);
                    if ($pkey) {
                        $pub = @openssl_pkey_get_details($pkey);
                        if ($pub && isset($pub['key'])) {
                            @file_put_contents($tmp_pub_file, $pub['key']);
                            $tmp_pub = $tmp_pub_file;
                        }
                        @openssl_free_key($pkey);
                    }
                }
            }

            $authed = @ssh2_auth_pubkey_file(
                $conn,
                $this->username,
                $tmp_pub,
                $tmp_key,
                $this->password !== '' ? $this->password : null
            );

            if (!$authed && $this->password !== '') {
                $authed = @ssh2_auth_password($conn, $this->username, $this->password);
            }

            @unlink($tmp_key);
            if ($tmp_pub !== '') @unlink($tmp_pub);
        } elseif ($this->password !== '') {
            $authed = @ssh2_auth_password($conn, $this->username, $this->password);
        }

        if (!$authed) {
            return array('ok' => false, 'error' => 'sftp_auth_failed', 'error_key' => 'sftp_login_failed');
        }

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) {
            return array('ok' => false, 'error' => 'sftp_init_failed');
        }

        $target_path = '/';
        if ($this->path_prefix !== '') {
            $target_path = '/' . $this->path_prefix . '/';
            $stat = @ssh2_sftp_stat($sftp, $this->path_prefix);
            if ($stat === false) {
                return array('ok' => false, 'error' => 'sftp_cd_failed', 'error_key' => 'sftp_path_inaccessible');
            }
        }

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();
        $remote_path = $target_path . $tmp;

        $stream = @fopen('ssh2.sftp://' . intval($sftp) . $remote_path, 'w');
        if (!$stream) {
            return array('ok' => false, 'error' => 'sftp_write_failed', 'error_key' => 'sftp_not_writable');
        }
        fwrite($stream, $content);
        fclose($stream);

        $dir = @opendir('ssh2.sftp://' . intval($sftp) . $target_path);
        if ($dir) {
            $found = false;
            while (($entry = readdir($dir)) !== false) {
                if ($entry === $tmp) { $found = true; break; }
            }
            closedir($dir);
        }

        @ssh2_sftp_unlink($sftp, ltrim($remote_path, '/'));

        return array('ok' => true);
    }

    /* ---------- Remote file operations (Task 3: browse / download loop) ----------
     * Key semantics match gojs_backup_execute_schedule / gojs_retention_prune:
     * the incoming key is the full remote key relative to the SFTP root (includes path_prefix).
     * listObjects returns a plain array [{key,size,last_modified}].
     */

    // Establish an ssh2 connection and authenticate; returns array('conn' => $conn, 'sftp' => $sftp), or null on failure.
    private function connect_ssh2() {
        if (!extension_loaded('ssh2')) return null;
        $conn = @ssh2_connect($this->host, $this->port);
        if (!$conn) return null;

        $authed = false;
        if ($this->private_key !== '') {
            $tmp_pub = '';
            $tmp_key = tempnam(sys_get_temp_dir(), 'gojs_sftp_key_');
            $tmp_pub_file = $tmp_key . '.pub';
            @file_put_contents($tmp_key, $this->private_key);
            @chmod($tmp_key, 0600);

            $lines = explode("\n", trim($this->private_key));
            if (strpos($lines[0] ?? '', 'PRIVATE KEY') !== false) {
                if (function_exists('openssl_pkey_get_private')) {
                    $pkey = @openssl_pkey_get_private($this->private_key);
                    if ($pkey) {
                        $pub = @openssl_pkey_get_details($pkey);
                        if ($pub && isset($pub['key'])) {
                            @file_put_contents($tmp_pub_file, $pub['key']);
                            $tmp_pub = $tmp_pub_file;
                        }
                        @openssl_free_key($pkey);
                    }
                }
            }

            $authed = @ssh2_auth_pubkey_file(
                $conn,
                $this->username,
                $tmp_pub,
                $tmp_key,
                $this->password !== '' ? $this->password : null
            );

            if (!$authed && $this->password !== '') {
                $authed = @ssh2_auth_password($conn, $this->username, $this->password);
            }

            @unlink($tmp_key);
            if ($tmp_pub !== '') @unlink($tmp_pub);
        } elseif ($this->password !== '') {
            $authed = @ssh2_auth_password($conn, $this->username, $this->password);
        }

        if (!$authed) return null;

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) return null;
        return array('conn' => $conn, 'sftp' => $sftp);
    }

    private function remote_url($sftp, $path) {
        return 'ssh2.sftp://' . intval($sftp) . '/' . ltrim($path, '/');
    }

    public function putObject(string $key, $body): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $content = is_resource($body) ? stream_get_contents($body) : (string)$body;
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_put_');
        if (@file_put_contents($tmp_file, $content) === false) {
            @unlink($tmp_file);
            return array('ok' => false, 'error' => 'sftp_tmp_write_failed');
        }
        $ok = @ssh2_scp_send($ctx['conn'], $tmp_file, '/' . ltrim($key, '/'), 0644);
        @unlink($tmp_file);
        if (!$ok) {
            return array('ok' => false, 'error' => 'sftp_put_failed', 'error_key' => 'sftp_not_writable');
        }
        return array('ok' => true);
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $items = array();
        $ctx = $this->connect_ssh2();
        if (!$ctx) return $items;
        $dir = '/' . ltrim($prefix, '/');
        $dh = @opendir($this->remote_url($ctx['sftp'], $dir));
        if (!$dh) return $items;
        $dir_trim = rtrim($prefix, '/');
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir_trim !== '' ? $dir_trim . '/' . $entry : $entry;
            $stat = @ssh2_sftp_stat($ctx['sftp'], ltrim($full, '/'));
            if ($stat === false) {
                $stat = @stat($this->remote_url($ctx['sftp'], $full));
            }
            $size = (is_array($stat) && isset($stat['size'])) ? (int)$stat['size'] : 0;
            $mtime = (is_array($stat) && isset($stat['mtime']) && (int)$stat['mtime'] > 0) ? gmdate('c', (int)$stat['mtime']) : '';
            $items[] = array('key' => $full, 'size' => $size, 'last_modified' => $mtime);
            if (count($items) >= $max) break;
        }
        closedir($dh);
        return $items;
    }

    public function deleteObject(string $key): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $ok = @ssh2_sftp_unlink($ctx['sftp'], ltrim($key, '/'));
        if (!$ok) {
            return array('ok' => false, 'error' => 'sftp_delete_failed');
        }
        return array('ok' => true);
    }

    public function getObject(string $key): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
        $ok = @ssh2_scp_recv($ctx['conn'], '/' . ltrim($key, '/'), $tmp_file);
        if (!$ok) {
            @unlink($tmp_file);
            return array('ok' => false, 'error' => 'sftp_get_failed', 'error_key' => 'sftp_get_failed');
        }
        return array('ok' => true, 'tmp_path' => $tmp_file);
    }
}

function gojs_backup_destination_factory(array $dest) {
    $type = isset($dest['type']) ? $dest['type'] : '';
    $config = array();

    switch ($type) {
        case 's3':
            $config['access_key'] = isset($dest['access_key_enc']) ? gojs_unseal_secret($dest['access_key_enc']) : '';
            $config['secret_key'] = isset($dest['secret_key_enc']) ? gojs_unseal_secret($dest['secret_key_enc']) : '';
            $config['endpoint'] = $dest['endpoint'] ?? 's3.amazonaws.com';
            $config['region'] = $dest['region'] ?? 'us-east-1';
            $config['bucket'] = $dest['bucket'] ?? '';
            $config['sse'] = !empty($dest['sse']);
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            return new GOJS_S3_Destination($config);
        case 'ftp':
            $config['host'] = $dest['host'] ?? '';
            $config['port'] = isset($dest['port']) ? (int)$dest['port'] : 21;
            $config['username'] = $dest['username'] ?? '';
            $config['password'] = isset($dest['password_enc']) ? gojs_unseal_secret($dest['password_enc']) : '';
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            $config['use_tls'] = !empty($dest['use_tls']);
            return new GOJS_FTP_Destination($config);
        case 'sftp':
            $config['host'] = $dest['host'] ?? '';
            $config['port'] = isset($dest['port']) ? (int)$dest['port'] : 22;
            $config['username'] = $dest['username'] ?? '';
            $config['password'] = isset($dest['password_enc']) ? gojs_unseal_secret($dest['password_enc']) : '';
            $config['private_key'] = isset($dest['private_key_enc']) ? gojs_unseal_secret($dest['private_key_enc']) : '';
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            return new GOJS_SFTP_Destination($config);
        default:
            throw new RuntimeException('Unknown destination type: ' . $type);
    }
}

function gojs_api_backup_destinations_list() {
    $destinations = gojs_destinations_load();
    $redacted = array_map('gojs_destination_redact', $destinations);
    gojs_json_response(array('destinations' => $redacted));
}

function gojs_api_backup_destinations_create() {
    $body = gojs_get_body();
    if (!is_array($body) || empty($body['type'])) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'type 是必填项'), 400);
    }

    $id = 'dest_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $dest = array(
        'id' => $id,
        'name' => isset($body['name']) ? trim((string)$body['name']) : '',
        'type' => (string)$body['type'],
        'path_prefix' => isset($body['path_prefix']) ? (string)$body['path_prefix'] : '',
        'created_at' => time(),
        'last_test_ok' => null,
        'last_test_at' => null,
    );

    switch ($dest['type']) {
        case 's3':
            if (empty($body['name']) || empty($body['access_key']) || empty($body['secret_key']) || empty($body['bucket'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, access_key, secret_key, bucket 为必填项'), 400);
            }
            $dest['access_key_enc'] = gojs_seal_secret((string)$body['access_key']);
            $dest['secret_key_enc'] = gojs_seal_secret((string)$body['secret_key']);
            $dest['endpoint'] = isset($body['endpoint']) && $body['endpoint'] !== '' ? (string)$body['endpoint'] : 's3.amazonaws.com';
            $dest['region'] = isset($body['region']) ? (string)$body['region'] : 'us-east-1';
            $dest['bucket'] = (string)$body['bucket'];
            $dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            if (empty($body['name']) || empty($body['host']) || empty($body['username'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, host, username 为必填项'), 400);
            }
            $dest['host'] = (string)$body['host'];
            $dest['port'] = isset($body['port']) ? (int)$body['port'] : 21;
            $dest['username'] = (string)$body['username'];
            $dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            if (empty($body['name']) || empty($body['host']) || empty($body['username'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, host, username 为必填项'), 400);
            }
            $dest['host'] = (string)$body['host'];
            $dest['port'] = isset($body['port']) ? (int)$body['port'] : 22;
            $dest['username'] = (string)$body['username'];
            $dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $dest['private_key_enc'] = gojs_seal_secret((string)($body['private_key'] ?? ''));
            break;
        default:
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => '不支持的 type: ' . $dest['type']), 400);
    }

    $destinations = gojs_destinations_load();
    $destinations[] = $dest;
    gojs_destinations_save($destinations);
    gojs_log_operation('backup_destination_create', $dest['name'], true, $dest['type']);

    gojs_json_response(array('destination' => gojs_destination_redact($dest)));
}

function gojs_api_backup_destinations_update($id) {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $destinations = gojs_destinations_load();
    $idx = null;
    foreach ($destinations as $i => $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '目标不存在'), 404);
    }

    $dest = $destinations[$idx];

    if (isset($body['name'])) $dest['name'] = trim((string)$body['name']);
    if (isset($body['path_prefix'])) $dest['path_prefix'] = (string)$body['path_prefix'];

    switch ($dest['type']) {
        case 's3':
            if (isset($body['access_key']) && $body['access_key'] !== '' && $body['access_key'] !== '****') {
                $dest['access_key_enc'] = gojs_seal_secret((string)$body['access_key']);
            }
            if (isset($body['secret_key']) && $body['secret_key'] !== '' && $body['secret_key'] !== '****') {
                $dest['secret_key_enc'] = gojs_seal_secret((string)$body['secret_key']);
            }
            if (isset($body['endpoint'])) $dest['endpoint'] = (string)$body['endpoint'];
            if (isset($body['region'])) $dest['region'] = (string)$body['region'];
            if (isset($body['bucket'])) $dest['bucket'] = (string)$body['bucket'];
            if (isset($body['sse'])) $dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            if (isset($body['host'])) $dest['host'] = (string)$body['host'];
            if (isset($body['port'])) $dest['port'] = (int)$body['port'];
            if (isset($body['username'])) $dest['username'] = (string)$body['username'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $dest['password_enc'] = gojs_seal_secret((string)$body['password']);
            }
            if (isset($body['use_tls'])) $dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            if (isset($body['host'])) $dest['host'] = (string)$body['host'];
            if (isset($body['port'])) $dest['port'] = (int)$body['port'];
            if (isset($body['username'])) $dest['username'] = (string)$body['username'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $dest['password_enc'] = gojs_seal_secret((string)$body['password']);
            }
            if (isset($body['private_key']) && $body['private_key'] !== '' && $body['private_key'] !== '****') {
                $dest['private_key_enc'] = gojs_seal_secret((string)$body['private_key']);
            }
            break;
    }

    $destinations[$idx] = $dest;
    gojs_destinations_save($destinations);
    gojs_log_operation('backup_destination_update', $dest['name'], true, $dest['type']);

    gojs_json_response(array('destination' => gojs_destination_redact($dest)));
}

function gojs_api_backup_destinations_delete($id) {
    $destinations = gojs_destinations_load();
    $kept = array();
    $deleted = null;
    foreach ($destinations as $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $deleted = $d;
        } else {
            $kept[] = $d;
        }
    }
    if ($deleted === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '目标不存在'), 404);
    }
    gojs_destinations_save($kept);
    gojs_log_operation('backup_destination_delete', $deleted['name'] ?? $id, true, $deleted['type'] ?? '');

    gojs_json_response(array('success' => true));
}

function gojs_api_backup_destinations_test() {
    $body = gojs_get_body();
    if (!is_array($body) || empty($body['type'])) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'type 是必填项'), 400);
    }

    $type = (string)$body['type'];
    $tmp_dest = array('type' => $type, 'path_prefix' => $body['path_prefix'] ?? '');

    switch ($type) {
        case 's3':
            $tmp_dest['access_key_enc'] = gojs_seal_secret((string)($body['access_key'] ?? ''));
            $tmp_dest['secret_key_enc'] = gojs_seal_secret((string)($body['secret_key'] ?? ''));
            $tmp_dest['endpoint'] = $body['endpoint'] ?? 's3.amazonaws.com';
            $tmp_dest['region'] = $body['region'] ?? 'us-east-1';
            $tmp_dest['bucket'] = $body['bucket'] ?? '';
            $tmp_dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            $tmp_dest['host'] = $body['host'] ?? '';
            $tmp_dest['port'] = isset($body['port']) ? (int)$body['port'] : 21;
            $tmp_dest['username'] = $body['username'] ?? '';
            $tmp_dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $tmp_dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            $tmp_dest['host'] = $body['host'] ?? '';
            $tmp_dest['port'] = isset($body['port']) ? (int)$body['port'] : 22;
            $tmp_dest['username'] = $body['username'] ?? '';
            $tmp_dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $tmp_dest['private_key_enc'] = gojs_seal_secret((string)($body['private_key'] ?? ''));
            break;
        default:
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => '不支持的 type: ' . $type), 400);
    }

    try {
        $adapter = gojs_backup_destination_factory($tmp_dest);
        $result = $adapter->testConnection();
    } catch (Throwable $e) {
        $result = array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'unexpected_error');
    }

    $ok = !empty($result['ok']);
    if (!empty($body['id'])) {
        $id = (string)$body['id'];
        $destinations = gojs_destinations_load();
        foreach ($destinations as $i => $d) {
            if (isset($d['id']) && $d['id'] === $id) {
                $destinations[$i]['last_test_ok'] = $ok;
                $destinations[$i]['last_test_at'] = time();
                gojs_destinations_save($destinations);
                break;
            }
        }
    }

    gojs_json_response($result);
}

/* ============================================================
   Remote backup browse / download (Task 3: remote restore loop)
   ============================================================ */

// Remote backup filename format (consistent with gojs_backup_execute_schedule upload and gojs_retention_prune cleanup).
function gojs_remote_backup_key_valid($key) {
    if (!is_string($key) || $key === '') return false;
    // Prevent path traversal: allow only fixed-format filenames (no / or ..).
    $basename = basename($key);
    return preg_match('/^(gojs-backup-\d{8}_\d{6}|backup-\d{8}-\d{6})\.zip$/', $basename) === 1;
}

// List remote gojs-backup-*.zip backup files.
function gojs_api_backup_destinations_browse() {
    $body = gojs_get_body();
    $dest_id = isset($body['dest_id']) ? (string)$body['dest_id'] : '';
    if ($dest_id === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'dest_id 是必填项'), 400);
    }

    $dest = null;
    foreach (gojs_destinations_load() as $d) {
        if (isset($d['id']) && $d['id'] === $dest_id) { $dest = $d; break; }
    }
    if (!$dest) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '备份目标不存在'), 404);
    }

    try {
        $adapter = gojs_backup_destination_factory($dest);
        if (!method_exists($adapter, 'listObjects')) {
            gojs_json_response(null, array('code' => 'adapter_unsupported', 'message' => '该备份目标不支持远端浏览'), 400);
        }

        $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
        $list_prefix = $path_prefix !== '' ? $path_prefix . '/gojs-backup-' : 'gojs-backup-';
        $objects = $adapter->listObjects($list_prefix, 1000);

        $items = array();
        if (is_array($objects)) {
            foreach ($objects as $o) {
                if (!is_array($o)) continue;
                $key = isset($o['key']) ? (string)$o['key'] : '';
                if ($key === '' || !gojs_remote_backup_key_valid($key)) continue;
                $modified = isset($o['last_modified']) ? (string)$o['last_modified'] : '';
                if ($modified !== '') {
                    $ts = strtotime($modified);
                    if ($ts !== false && $ts > 0) $modified = gmdate('c', $ts);
                }
                $items[] = array(
                    'key' => $key,
                    'size' => isset($o['size']) ? (int)$o['size'] : 0,
                    'modified' => $modified,
                );
            }
        }

        gojs_json_response(array('items' => $items, 'prefix' => $list_prefix));
    } catch (Throwable $e) {
        gojs_json_response(null, array('code' => 'browse_failed', 'message' => $e->getMessage()), 400);
    }
}

// Pull a remote backup into local CONFIG_DIR/backups/; returns the local filename so the existing restore flow can be used.
function gojs_api_backup_destinations_download() {
    $body = gojs_get_body();
    $dest_id = isset($body['dest_id']) ? (string)$body['dest_id'] : '';
    $key = isset($body['key']) ? (string)$body['key'] : '';
    if ($dest_id === '' || $key === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'dest_id 与 key 为必填项'), 400);
    }

    if (!gojs_remote_backup_key_valid($key)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $dest = null;
    foreach (gojs_destinations_load() as $d) {
        if (isset($d['id']) && $d['id'] === $dest_id) { $dest = $d; break; }
    }
    if (!$dest) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '备份目标不存在'), 404);
    }

    try {
        $adapter = gojs_backup_destination_factory($dest);
        if (!method_exists($adapter, 'getObject')) {
            gojs_json_response(null, array('code' => 'adapter_unsupported', 'message' => '该备份目标不支持远端下载'), 400);
        }

        $res = $adapter->getObject($key);
        if (empty($res['ok'])) {
            gojs_json_response(null, array(
                'code' => 'download_failed',
                'message' => isset($res['error']) ? $res['error'] : '从远端下载失败',
            ), 400);
        }
        if (empty($res['tmp_path']) || !is_file($res['tmp_path'])) {
            gojs_json_response(null, array(
                'code' => 'download_failed',
                'message' => '下载结果无效',
            ), 500);
        }

        $backup_dir = CONFIG_DIR . '/backups';
        if (!is_dir($backup_dir)) {
            if (!@mkdir($backup_dir, 0700, true)) {
                @unlink($res['tmp_path']);
                gojs_json_response(null, array('code' => 'mkdir_failed', 'message' => '无法创建备份目录'), 500);
            }
        }

        // Local filename is normalized to the backup-YYYYmmdd-HHMMSS.zip format recognized by the existing restore flow.
        $basename = basename($key);
        $local_name = $basename;
        if (strpos($local_name, 'gojs-backup-') === 0) {
            $local_name = 'backup-' . substr($local_name, strlen('gojs-backup-'));
            $local_name = str_replace('_', '-', $local_name);
        }

        $target = $backup_dir . '/' . $local_name;
        if (is_file($target)) {
            @unlink($target);
        }
        if (!@rename($res['tmp_path'], $target)) {
            if (!@copy($res['tmp_path'], $target)) {
                @unlink($res['tmp_path']);
                gojs_json_response(null, array('code' => 'save_failed', 'message' => '保存备份文件失败'), 500);
            }
            @unlink($res['tmp_path']);
        }

        $size = @filesize($target);
        gojs_log_operation('backup_remote_download', $local_name, true);

        // monitor: remote backup download counts as panel inbound traffic.
        gojs_monitor_bump_bandwidth($size ? $size : 0, 0);

        gojs_json_response(array('filename' => $local_name, 'size' => $size, 'downloaded' => true));
    } catch (Throwable $e) {
        gojs_json_response(null, array(
            'code' => 'download_failed',
            'message' => $e->getMessage(),
        ), 400);
    }
}

/* ============================================================
   A.1 CRON expression parser + next_run_at calculator
   ============================================================ */
