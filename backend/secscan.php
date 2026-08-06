<?php

// Security scan: frontend/backend dependency vulnerability scanning.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_secscan_cache_path(): string {
    return CONFIG_DIR . '/secscan_cache.json';
}

function gojs_secscan_severity_to_badge(string $s): string {
    $s = strtolower($s);
    if ($s === 'critical' || $s === 'danger') return 'danger';
    if ($s === 'high') return 'danger';
    if ($s === 'moderate') return 'warning';
    if ($s === 'low') return 'muted';
    if ($s === 'info') return 'accent';
    return 'muted';
}

/** Normalize a version string for version_compare: strip leading v/V and +build suffix. */
function gojs_secscan_normalize_version(string $v): string {
    $v = trim($v);
    $v = preg_replace('/^[vV]/', '', $v);
    $v = preg_replace('/\+.*$/', '', $v);
    return trim($v);
}

function gojs_secscan_version_compare(string $a, string $op, string $b): bool {
    return version_compare(gojs_secscan_normalize_version($a), gojs_secscan_normalize_version($b), $op);
}

/**
 * Parse a single version constraint clause (space-separated comparisons are AND-combined).
 * Supports: *, X.* / X.Y.* ranges, "A - B" closed intervals, single comparators, and bare versions.
 */
function gojs_secscan_parse_single_range(string $pkg_version, string $range): bool {
    $range = trim($range);
    if ($range === '') return true;
    // Wildcard: * matches any version.
    if ($range === '*') return true;
    // Wildcard prefix -> range: X.* -> >=X.0.0 <X+1.0.0; X.Y.* -> >=X.Y.0 <X.(Y+1).0 (carries at Y=9).
    if (preg_match('/^(\d+)(?:\.(\d+))?\.\*$/', $range, $wm)) {
        $major = (int)$wm[1];
        $minor = isset($wm[2]) && $wm[2] !== '' ? (int)$wm[2] : 0;
        $has_minor = isset($wm[2]) && $wm[2] !== '';
        $hi_major = $major;
        $hi_minor = $has_minor ? $minor + 1 : 0;
        if ($hi_minor >= 10) {
            $hi_major += 1;
            $hi_minor = 0;
        }
        if ($has_minor) {
            return gojs_secscan_version_compare($pkg_version, '>=', $major . '.' . $minor . '.0')
                && gojs_secscan_version_compare($pkg_version, '<', $hi_major . '.' . $hi_minor . '.0');
        }
        return gojs_secscan_version_compare($pkg_version, '>=', $major . '.0.0')
            && gojs_secscan_version_compare($pkg_version, '<', ($major + 1) . '.0.0');
    }
    // Closed interval: A - B.
    if (preg_match('/^(.+?)\s*-\s*(.+)$/', $range, $m)) {
        $lo = trim($m[1]);
        $hi = trim($m[2]);
        return gojs_secscan_version_compare($pkg_version, '>=', $lo)
            && gojs_secscan_version_compare($pkg_version, '<=', $hi);
    }
    // Space-separated compound comparisons (AND), e.g. ">=1.0.0 <1.0.21" (must precede single-comparator handling).
    $tokens = preg_split('/\s+/', $range);
    if (count($tokens) > 1) {
        $match = true;
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || $tok === '*') continue;
            if (!gojs_secscan_parse_single_range($pkg_version, $tok)) {
                $match = false;
                break;
            }
        }
        return $match;
    }
    // Single comparator.
    if (preg_match('/^(<|<=|==|=|>=|>|!=|<>)\s*(\S+)$/', $range, $m)) {
        $op = $m[1];
        if ($op === '=') $op = '==';
        $ver = trim($m[2]);
        return gojs_secscan_version_compare($pkg_version, $op, $ver);
    }
    // Bare version: exact equality.
    return gojs_secscan_version_compare($pkg_version, '==', $range);
}

/**
 * Expand composer semantic constraints into comma-separated AND conditions
 * (^X.Y[.Z], ~X.Y, ~X.Y.Z). Unrecognized input is returned unchanged.
 */
function gojs_secscan_expand_composer(string $cond): string {
    $cond = trim($cond);
    if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $cond, $m)) {
        $major = (int)$m[1];
        $minor = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $patch = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        $lo = $m[1];
        if (isset($m[2]) && $m[2] !== '') $lo .= '.' . $m[2];
        if (isset($m[3]) && $m[3] !== '') $lo .= '.' . $m[3];
        if ($major > 0) {
            return '>=' . $lo . ',<' . ($major + 1) . '.0.0';
        }
        return '>=' . $lo . ',<0.' . ($minor + 1) . '.0';
    }
    if (preg_match('/^~(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $cond, $m)) {
        $major = (int)$m[1];
        $minor = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $lo = $m[1];
        if (isset($m[2]) && $m[2] !== '') $lo .= '.' . $m[2];
        if (isset($m[3]) && $m[3] !== '') $lo .= '.' . $m[3];
        if (isset($m[3]) && $m[3] !== '') {
            return '>=' . $lo . ',<' . $major . '.' . ($minor + 1) . '.0';
        }
        return '>=' . $lo . ',<' . ($major + 1) . '.0.0';
    }
    return $cond;
}

function gojs_secscan_parse_range(string $pkg_version, string $range): bool {
    $ors = explode('||', $range);
    foreach ($ors as $or_part) {
        $or_part = trim($or_part);
        // Empty branch or * means a match (any version hits).
        if ($or_part === '' || $or_part === '*') return true;
        // Expand ^ / ~ semantics, split AND by comma: all conditions must hold.
        $expanded = trim(gojs_secscan_expand_composer($or_part));
        $and_parts = explode(',', $expanded);
        $match = true;
        foreach ($and_parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if ($part === '*') continue;
            if (!gojs_secscan_parse_single_range($pkg_version, $part)) {
                $match = false;
                break;
            }
        }
        if ($match) return true;
    }
    return false;
}

$GLOBALS['GOJS_PHP_CVE_SEED'] = [
    ['name'=>'phpunit/phpunit',               'vuln_range'=>'< 9.0.0',  'severity'=>'low',      'title'=>'PHPUnit older than 9: eval injection in test harness', 'url'=>'https://github.com/sebastianbergmann/phpunit/security/advisories'],
    ['name'=>'guzzlehttp/guzzle',             'vuln_range'=>'>=6.0.0 <6.5.6 || >=7.0.0 <7.4.3', 'severity'=>'high', 'title'=>'Guzzle cookie-domain confusion CVE-2022-29248',          'url'=>'https://github.com/guzzle/guzzle/security/advisories/GHSA-cwmx-hcrq-mhc3'],
    ['name'=>'phpseclib/phpseclib',           'vuln_range'=>'>=1.0.0 <1.0.21 || >=2.0.0 <2.0.37 || >=3.0.0 <3.0.13', 'severity'=>'high', 'title'=>'phpseclib RSA signature forgery CVE-2021-30132',        'url'=>'https://github.com/phpseclib/phpseclib/issues/1629'],
    ['name'=>'symfony/http-kernel',           'vuln_range'=>'>=5.0.0 <5.4.22 || >=6.0.0 <6.0.20 || >=6.1.0 <6.1.12 || >=6.2.0 <6.2.6', 'severity'=>'critical', 'title'=>'Symfony FragmentListener bypass CVE-2022-24894',   'url'=>'https://symfony.com/cve-2022-24894'],
    ['name'=>'symfony/security-core',         'vuln_range'=>'>=4.0.0 <4.4.50 || >=5.0.0 <5.4.20 || >=6.0.0 <6.2.6', 'severity'=>'high',     'title'=>'Symfony security-core Auth auth bypass',             'url'=>'https://github.com/symfony/symfony/security/advisories'],
    ['name'=>'laravel/framework',             'vuln_range'=>'>=8.0.0 <8.75.0 || >=9.0.0 <9.33.0', 'severity'=>'critical',      'title'=>'Laravel framework cookie-based RCE',                'url'=>'https://laravel.com/docs/security'],
    ['name'=>'league/flysystem',              'vuln_range'=>'>=1.0.0 <1.1.4 || >=2.0.0 <2.1.1',    'severity'=>'moderate', 'title'=>'Flysystem path traversal CVE-2021-32708',           'url'=>'https://github.com/thephpleague/flysystem/security/advisories/GHSA-7hh3-wv9w-xgvr'],
    ['name'=>'twig/twig',                     'vuln_range'=>'>=2.0.0 <2.15.3 || >=3.0.0 <3.4.3',   'severity'=>'moderate', 'title'=>'Twig Sandbox mode bypass',                           'url'=>'https://github.com/twigphp/Twig/tags'],
    ['name'=>'smarty/smarty',                 'vuln_range'=>'< 4.3.0',                'severity'=>'high',     'title'=>'Smarty template injection',                          'url'=>'https://github.com/smarty-php/smarty/security/advisories'],
    ['name'=>'monolog/monolog',               'vuln_range'=>'>=2.0.0 <2.9.0 || >=3.0.0 <3.5.0',    'severity'=>'moderate', 'title'=>'Monolog SwiftMailerHandler CRLF header injection',   'url'=>'https://github.com/Seldaek/monolog/tags'],
    ['name'=>'doctrine/dbal',                 'vuln_range'=>'>=3.0.0 <3.6.4 || >=2.0.0 <2.13.9',   'severity'=>'high',     'title'=>'Doctrine DBAL SQL injection via LIMIT parameters',   'url'=>'https://github.com/doctrine/dbal/security/advisories'],
    ['name'=>'doctrine/orm',                  'vuln_range'=>'>=2.0.0 <2.14.3 || >=2.0.0 <2.13.4',  'severity'=>'high',     'title'=>'Doctrine ORM order-by SQL injection',                'url'=>'https://github.com/doctrine/orm/security/advisories'],
    ['name'=>'wordpress/core',                'vuln_range'=>'< 6.2',                  'severity'=>'critical', 'title'=>'WordPress core <6.2 multiple XSS and auth issues',   'url'=>'https://wordpress.org/support/wordpress-version/version-6-2/'],
    ['name'=>'drupal/core',                   'vuln_range'=>'>=9.0.0 <9.5.8 || >=10.0.0 <10.0.8',   'severity'=>'critical', 'title'=>'Drupal core SA-CORE multiple vulns',                'url'=>'https://www.drupal.org/security'],
    ['name'=>'joomla/joomla-cms',             'vuln_range'=>'< 4.2.8',                'severity'=>'critical', 'title'=>'Joomla! CMS CVE-2023-23752',                        'url'=>'https://developer.joomla.org/security-centre.html'],
    ['name'=>'magento/product-community-edition', 'vuln_range'=>'< 2.4.6-p1',       'severity'=>'critical', 'title'=>'Magento 2.4.6 pre-p1 RCE',                           'url'=>'https://helpx.adobe.com/security/products/magento.html'],
    ['name'=>'phpmailer/phpmailer',           'vuln_range'=>'< 6.5.0',                'severity'=>'high',     'title'=>'PHPMailer CVE-2020-36326/36327 object injection',    'url'=>'https://github.com/PHPMailer/PHPMailer/tags'],
    ['name'=>'erusev/parsedown',              'vuln_range'=>'< 1.7.4',                'severity'=>'moderate', 'title'=>'Parsedown XSS CVE-2018-1000163',                     'url'=>'https://github.com/erusev/parsedown/issues'],
    ['name'=>'michelf/php-markdown',          'vuln_range'=>'< 1.9.0',                'severity'=>'moderate', 'title'=>'PHP Markdown Lib XSS',                               'url'=>'https://github.com/michelf/php-markdown'],
    ['name'=>'cakephp/cakephp',               'vuln_range'=>'< 4.4.11 || < 3.10.12', 'severity'=>'critical', 'title'=>'CakePHP cache-engine RCE',                            'url'=>'https://bakery.cakephp.org/'],
    // Additional entries (real advisories; ranges confirmed by official announcements to avoid multi-series false positives).
    ['name'=>'phpseclib/phpseclib',           'vuln_range'=>'<1.0.23 || >=2.0.0 <2.0.46 || >=3.0.0 <3.0.34', 'severity'=>'high', 'title'=>'phpseclib BinaryField DoS CVE-2023-49316',        'url'=>'https://github.com/phpseclib/phpseclib/releases/tag/3.0.34'],
    ['name'=>'guzzlehttp/psr7',               'vuln_range'=>'<1.9.1 || >=2.0.0 <2.4.5', 'severity'=>'high', 'title'=>'guzzlehttp/psr7 HTTP multiline header injection CVE-2023-29197', 'url'=>'https://github.com/guzzle/psr7/security/advisories/GHSA-wxmh-65f7-jcvw'],
    ['name'=>'symfony/runtime',               'vuln_range'=>'>=5.3.0 <5.4.46 || >=6.0.0 <6.4.14 || >=7.0.0 <7.1.7', 'severity'=>'moderate', 'title'=>'Symfony runtime env/debug switch via crafted query CVE-2024-50340', 'url'=>'https://symfony.com/cve-2024-50340'],
    ['name'=>'laminas/laminas-diactoros',     'vuln_range'=>'<2.18.1 || >=2.19.0 <2.19.1 || >=2.20.0 <2.20.1 || >=2.21.0 <2.21.1 || >=2.22.0 <2.22.1 || >=2.23.0 <2.23.1 || >=2.24.0 <2.24.2 || >=2.25.0 <2.25.2', 'severity'=>'high', 'title'=>'laminas-diactoros multiline header termination CVE-2023-29530', 'url'=>'https://github.com/laminas/laminas-diactoros/security/advisories/GHSA-xv3h-4844-9h36'],
];

function gojs_secscan_load_cache(): array {
    $path = gojs_secscan_cache_path();
    if (!file_exists($path)) return array();
    $data = gojs_read_json_lock_safe($path, array());
    return is_array($data) ? $data : array();
}

function gojs_secscan_save_cache(array $data): void {
    gojs_write_json_lock_safe(gojs_secscan_cache_path(), $data, true);
}

function gojs_secscan_frontend(bool $force=false): array {
    $cache = gojs_secscan_load_cache();
    $now = time();

    if (!$force && isset($cache['frontend_cache']) && is_array($cache['frontend_cache'])) {
        $fc = $cache['frontend_cache'];
        if (isset($fc['scanned_at']) && ($now - (int)$fc['scanned_at']) < 3600) {
            return $fc;
        }
    }

    $exec_avail = function_exists('exec') && function_exists('shell_exec');
    if (!$exec_avail) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $cwd = ROOT;
    $cmd = 'npm audit --omit=dev --json 2>&1';
    $descriptorspec = array(
       0 => array('pipe', 'r'),
       1 => array('pipe', 'w'),
       2 => array('pipe', 'w')
    );
    $process = @proc_open($cmd, $descriptorspec, $pipes, $cwd);
    $output = '';
    $proc_success = false;
    if (is_resource($process)) {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ret = proc_close($process);
        if ($output !== '' && $output !== false) {
            $proc_success = true;
        }
    }
    if (!$proc_success) {
        $output = '';
        if (function_exists('shell_exec')) {
            $old_cwd = getcwd();
            if ($old_cwd) @chdir($cwd);
            $raw = @shell_exec($cmd);
            if ($old_cwd) @chdir($old_cwd);
            if ($raw !== null && $raw !== false) $output = $raw;
        }
    }

    if ($output === '' || $output === null) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $parsed = json_decode($output, true);
    if (!is_array($parsed)) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $vulns = array();
    $seen_keys = array();

    $severity_order = array('info' => 0, 'low' => 1, 'moderate' => 2, 'high' => 3, 'critical' => 4);

    $candidates = array();
    if (isset($parsed['vulnerabilities']) && is_array($parsed['vulnerabilities'])) {
        foreach ($parsed['vulnerabilities'] as $pkg => $v) {
            if (!is_array($v)) continue;
            $installed = isset($v['name']) ? (string)$v['name'] : (string)$pkg;
            $iv = isset($v['range']) ? (string)$v['range'] : '';
            if (preg_match('/^[<>=!]*\s*([\d][\w\.\-+]*)$/', $iv, $mv)) {
                $iv = $mv[1];
            }
            $title = isset($v['title']) ? (string)$v['title'] : '';
            $sev = isset($v['severity']) ? strtolower((string)$v['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $url = isset($v['url']) ? (string)$v['url'] : (isset($v['advisory']) ? (string)$v['advisory'] : '');
            $fix_info = isset($v['fixAvailable']) ? $v['fixAvailable'] : null;
            $fixed = null;
            if (is_array($fix_info) && isset($fix_info['name']) && isset($fix_info['version'])) {
                $fixed = (string)$fix_info['version'];
            } elseif (isset($v['fixedVersion']) && $v['fixedVersion'] !== '' && $v['fixedVersion'] !== '*') {
                $fixed = (string)$v['fixedVersion'];
            }
            $vias = isset($v['via']) && is_array($v['via']) ? $v['via'] : array();
            if (count($vias) > 0) {
                foreach ($vias as $via) {
                    if (is_array($via)) {
                        $v2 = $via;
                        $v2_pkg = isset($v2['name']) ? (string)$v2['name'] : (string)$pkg;
                        $v2_title = isset($v2['title']) ? (string)$v2['title'] : $title;
                        $v2_sev = isset($v2['severity']) ? strtolower((string)$v2['severity']) : $sev;
                        if (!in_array($v2_sev, array('info','low','moderate','high','critical'), true)) $v2_sev = 'info';
                        $v2_url = isset($v2['url']) ? (string)$v2['url'] : $url;
                        $v2_fixed = $fixed;
                        if (isset($v2['fixAvailable']) && is_array($v2['fixAvailable']) && isset($v2['fixAvailable']['version'])) {
                            $v2_fixed = (string)$v2['fixAvailable']['version'];
                        }
                        $candidates[] = array(
                            'package' => $v2_pkg,
                            'installed_version' => $iv,
                            'fixed_version' => $v2_fixed,
                            'severity' => $v2_sev,
                            'title' => $v2_title,
                            'url' => $v2_url,
                        );
                    }
                }
            } else {
                $candidates[] = array(
                    'package' => (string)$pkg,
                    'installed_version' => $iv,
                    'fixed_version' => $fixed,
                    'severity' => $sev,
                    'title' => $title,
                    'url' => $url,
                );
            }
        }
    }

    if (isset($parsed['advisories']) && is_array($parsed['advisories'])) {
        foreach ($parsed['advisories'] as $adv) {
            if (!is_array($adv)) continue;
            $pkg = isset($adv['module_name']) ? (string)$adv['module_name'] : (isset($adv['name']) ? (string)$adv['name'] : '');
            $iv = isset($adv['installed_version']) ? (string)$adv['installed_version'] : (isset($adv['version']) ? (string)$adv['version'] : '');
            $title = isset($adv['title']) ? (string)$adv['title'] : (isset($adv['overview']) ? (string)$adv['overview'] : '');
            $sev = isset($adv['severity']) ? strtolower((string)$adv['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $url = isset($adv['url']) ? (string)$adv['url'] : (isset($adv['references']) ? (string)$adv['references'] : '');
            $fixed = isset($adv['patched_versions']) ? (string)$adv['patched_versions'] : (isset($adv['fixed_version']) ? (string)$adv['fixed_version'] : null);
            if ($pkg !== '') {
                $candidates[] = array(
                    'package' => $pkg,
                    'installed_version' => $iv,
                    'fixed_version' => $fixed,
                    'severity' => $sev,
                    'title' => $title,
                    'url' => $url,
                );
            }
        }
    }

    foreach ($candidates as $c) {
        $key = $c['package'] . '||' . $c['installed_version'];
        $sev_rank = isset($severity_order[$c['severity']]) ? $severity_order[$c['severity']] : 0;
        if (isset($seen_keys[$key])) {
            $idx = $seen_keys[$key];
            $existing_rank = isset($severity_order[$vulns[$idx]['severity']]) ? $severity_order[$vulns[$idx]['severity']] : 0;
            if ($sev_rank > $existing_rank) {
                $vulns[$idx]['severity'] = $c['severity'];
                $vulns[$idx]['title'] = $c['title'] ?: $vulns[$idx]['title'];
                $vulns[$idx]['url'] = $c['url'] ?: $vulns[$idx]['url'];
                if ($c['fixed_version']) $vulns[$idx]['fixed_version'] = $c['fixed_version'];
                $vulns[$idx]['severityBadgeVariant'] = gojs_secscan_severity_to_badge($c['severity']);
            }
            continue;
        }
        $seen_keys[$key] = count($vulns);
        $item = array(
            'package' => $c['package'],
            'installed_version' => $c['installed_version'],
            'fixed_version' => $c['fixed_version'] ?: null,
            'severity' => $c['severity'],
            'title' => $c['title'],
            'url' => $c['url'] ?: null,
            'severityBadgeVariant' => gojs_secscan_severity_to_badge($c['severity']),
        );
        $vulns[] = $item;
    }

    $result = array(
        'available' => true,
        'scanned_at' => $now,
        'vulns' => array_values($vulns),
    );
    $cache['frontend_cache'] = $result;
    gojs_secscan_save_cache($cache);
    return $result;
}

function gojs_secscan_backend(bool $force=false): array {
    $cache = gojs_secscan_load_cache();
    $now = time();

    if (!$force && isset($cache['backend_cache']) && is_array($cache['backend_cache'])) {
        $bc = $cache['backend_cache'];
        if (isset($bc['scanned_at']) && ($now - (int)$bc['scanned_at']) < 3600) {
            return $bc;
        }
    }

    $candidates = array(
        PANEL_ROOT . '/composer.lock',
        dirname(PANEL_ROOT) . '/composer.lock',
    );
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        if ($docroot !== '') {
            $candidates[] = $docroot . '/composer.lock';
        }
    }

    $lock_path = null;
    foreach ($candidates as $c) {
        if (file_exists($c) && is_file($c) && is_readable($c)) {
            $lock_path = $c;
            break;
        }
    }

    if ($lock_path === null) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }

    $raw = @file_get_contents($lock_path);
    if ($raw === false) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }
    $lock = json_decode($raw, true);
    if (!is_array($lock)) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }

    $packages = array();
    if (isset($lock['packages']) && is_array($lock['packages'])) {
        $packages = array_merge($packages, $lock['packages']);
    }
    if (isset($lock['packages-dev']) && is_array($lock['packages-dev'])) {
        $packages = array_merge($packages, $lock['packages-dev']);
    }

    $seed = isset($GLOBALS['GOJS_PHP_CVE_SEED']) && is_array($GLOBALS['GOJS_PHP_CVE_SEED'])
        ? $GLOBALS['GOJS_PHP_CVE_SEED']
        : array();

    $seed_by_name = array();
    foreach ($seed as $s) {
        $name = isset($s['name']) ? (string)$s['name'] : '';
        if ($name === '') continue;
        $seed_by_name[$name][] = $s;
    }

    $vulns = array();

    foreach ($packages as $pkg) {
        if (!is_array($pkg)) continue;
        $name = isset($pkg['name']) ? (string)$pkg['name'] : '';
        $version = isset($pkg['version']) ? (string)$pkg['version'] : '';
        if ($name === '' || $version === '') continue;
        $version = ltrim($version, 'vV');
        if (!isset($seed_by_name[$name])) continue;
        foreach ($seed_by_name[$name] as $seed_entry) {
            $range = isset($seed_entry['vuln_range']) ? (string)$seed_entry['vuln_range'] : '';
            if ($range === '') continue;
            if (!gojs_secscan_parse_range($version, $range)) continue;
            $sev = isset($seed_entry['severity']) ? strtolower((string)$seed_entry['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $title = isset($seed_entry['title']) ? (string)$seed_entry['title'] : '';
            $url = isset($seed_entry['url']) ? (string)$seed_entry['url'] : '';
            $vulns[] = array(
                'package' => $name,
                'installed_version' => $version,
                'fixed_version' => null,
                'severity' => $sev,
                'title' => $title,
                'url' => $url ?: null,
                'severityBadgeVariant' => gojs_secscan_severity_to_badge($sev),
            );
        }
    }

    $result = array(
        'available' => true,
        'scanned_at' => $now,
        'heuristicOnly' => false,
        'count' => count($vulns),
        'vulns' => $vulns,
    );
    $cache['backend_cache'] = $result;
    gojs_secscan_save_cache($cache);
    return $result;
}
