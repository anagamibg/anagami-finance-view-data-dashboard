<?php

namespace App\Services\Databackd\Data;


/**
 * Reads a publicly shared Google Sheet (or a published CSV of one) as CSV text, without OAuth.
 *
 * The sheet has to be shared with "Anyone with the link - Viewer". The class is self-contained
 * (no state, no logged-in user needed) so jobs and other packages can reuse it.
 *
 * SSRF guard (S6/P5): the user supplies the URL, so every request is made only after the URL passed assertSafeUrl():
 * https only, port 443, no user:pass@, host must be exactly docs.google.com (redirects may also lead to *.googleusercontent.com),
 * every resolved IP (A and AAAA) must be public, the connection is pinned to the validated IPs (CURLOPT_RESOLVE, no DNS rebinding),
 * redirects are followed by hand (max 3) and every hop is validated again, the response is capped in time and size.
 */
class GoogleSheetsReader
{

    /** Folder (inside public/) whose files can be read directly from disk in a local development environment (demo without internet). */
    const LOCAL_TEMPLATES_DIR = 'databackd/templates';

    /** The only hosts a source URL may point to (https, port 443). */
    const ALLOWED_HOSTS = ['docs.google.com'];

    /** Hosts a redirect may lead to: Google serves the CSV export from *.googleusercontent.com. "*." matches any sub-domain. */
    const ALLOWED_REDIRECT_HOSTS = ['docs.google.com', '*.googleusercontent.com'];

    /** A redirect to these hosts means "the sheet is not public" (sign-in page); they are never requested. */
    const SIGN_IN_HOSTS = ['accounts.google.com'];

    const MAX_REDIRECTS = 3;
    const CONNECT_TIMEOUT = 5;
    const TOTAL_TIMEOUT = 20;
    const MAX_URL_LENGTH = 2000;

    /** Maximum size of the (decompressed) response body. */
    const MAX_BYTES = 10485760;

    /** Maximum number of lines of the CSV (the importer parses the whole file in memory). */
    const MAX_LINES = 100000;

    protected ?int $companyId;

    /**
     * @param int|null $companyId only used for the importer that parses the CSV (default: company of the logged-in user, resolved lazily)
     */
    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId;
    }

    /**
     * Extract the spreadsheet id and the sheet id (gid) from a Google Sheets URL.
     *
     * Understands https://docs.google.com/spreadsheets/d/<id>/edit#gid=123, ...?gid=123, the multi-account form ".../spreadsheets/u/1/d/<id>/...",
     * ".../d/e/<id>/pubhtml" and ".../d/e/<id>/pub?output=csv" (published to the web) and a bare spreadsheet id. A link must be an https://docs.google.com/ link (see assertSafeUrl()): a link to any other
     * host is refused even when its path looks like a Google Sheets one.
     *
     * @return array{id: string, gid: ?string, sheet_name: ?string} sheet_name: a tab name from a gviz link (".../gviz/tq?sheet=Name")
     * @throws \RuntimeException when the value is not a Google Sheets URL or id
     */
    public function parseUrl(string $url): array
    {
        $url = trim($url);

        if (str_contains($url, '://') || preg_match('~^docs\.google\.com/~i', $url)) {
            // "docs.google.com/spreadsheets/..." pasted without the scheme is still accepted
            $link = str_contains($url, '://') ? $url : 'https://' . $url;

            try {
                $path = static::inspectUrl($link)['path'];
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(__('databackd-data-sources.errors.invalid_url'));
            }

            // the link is only used to find the id: the request itself is built by exportUrl() for docs.google.com
            // "/u/<n>/" is added by Google when the user is signed in to several accounts
            if (!preg_match('~^/spreadsheets/(?:u/\d+/)?d/(e/)?([a-zA-Z0-9_-]{10,})~', preg_split('~\?~', $path)[0], $matches)) {
                throw new \RuntimeException(__('databackd-data-sources.errors.invalid_url'));
            }

            $id = $matches[1] . $matches[2];
        }
        elseif (preg_match('~^[a-zA-Z0-9_-]{20,}$~', $url)) {
            $id = $url;
        }
        else {
            throw new \RuntimeException(__('databackd-data-sources.errors.invalid_url'));
        }

        $gid = null;
        $sheetName = null;

        if (preg_match('~[#?&]gid=(\d+)~', $url, $matches)) {
            $gid = $matches[1];
        }
        elseif (preg_match('~[?&]sheet=([^&#]+)~', $url, $matches)) {
            $sheetName = rawurldecode(str_replace('+', ' ', $matches[1]));
        }

        return ['id' => $id, 'gid' => $gid, 'sheet_name' => $sheetName];
    }

    /**
     * URL that returns the sheet as CSV. A $gid argument wins over the gid inside the URL; the default is the first sheet (gid 0;
     * for a published link: the first published sheet).
     *
     * A tab is selected only by its numeric gid: a tab name (in $gid or as "sheet=" of a gviz link) is refused instead of silently
     * importing the first tab - the gviz CSV endpoint that accepts names is not documented by Google.
     *
     * @throws \RuntimeException when the URL is invalid
     */
    public function exportUrl(string $url, ?string $gid = null): string
    {
        $parsed = $this->parseUrl($url);
        $normalized = $this->normalizeGid($gid);

        if (($normalized === null && trim((string)$gid) !== '') || ($normalized === null && $parsed['gid'] === null && $parsed['sheet_name'] !== null)) {
            throw new \RuntimeException(__('databackd-data-sources.errors.sheet_not_gid'));
        }

        $gid = $normalized ?? $parsed['gid'];

        if (str_starts_with($parsed['id'], 'e/')) {
            // "Publish to the web" link; without a gid Google returns the first published sheet (gid 0 may not be published)
            return 'https://docs.google.com/spreadsheets/d/' . $parsed['id'] . '/pub?output=csv' . ($gid !== null ? '&single=true&gid=' . $gid : '');
        }

        return 'https://docs.google.com/spreadsheets/d/' . $parsed['id'] . '/export?format=csv&gid=' . ($gid ?? '0');
    }

    /**
     * Download the sheet as CSV text.
     *
     * @throws \RuntimeException with a translated message when the sheet is not public (401/403/404 or a sign-in page), the URL is invalid or the network fails
     */
    public function fetchCsv(string $url, ?string $gid = null): string
    {
        return $this->download($this->exportUrl($url, $gid), true);
    }

    /**
     * Download a CSV from a URL. Only https://docs.google.com/... is fetched (e.g. a sheet "published to the web" as CSV);
     * see assertSafeUrl(). In a local development environment a file of public/databackd/templates/ is read directly from disk
     * (accepted forms: "public/databackd/templates/x.csv", "/databackd/templates/x.csv", "http(s)://host/databackd/templates/x.csv";
     * the host is ignored - no request is made).
     *
     * @throws \RuntimeException with a translated message
     */
    public function fetchCsvUrl(string $url): string
    {
        $url = trim($url);

        if ($this->canReadLocally()) {
            $path = $this->localTemplatePath($url);

            if ($path !== null) {
                if (!is_file($path)) {
                    throw new \RuntimeException(__('databackd-data-sources.errors.local_file_missing', ['file' => basename($path)]));
                }

                return (string)file_get_contents($path);
            }
        }

        return $this->download($url, false);
    }

    /**
     * Cheap, offline check of a source URL before it is stored or fetched (no DNS, no HTTP).
     *
     * @param string $type google_sheets|csv_url
     * @throws \RuntimeException with a translated message when the URL is not an allowed source
     */
    public function assertSourceUrl(string $type, string $url): void
    {
        $url = trim($url);

        if ($type === 'google_sheets') {
            $this->parseUrl($url);

            return;
        }

        if ($type === 'csv_url') {
            if ($this->canReadLocally() && $this->localTemplatePath($url) !== null) {
                return;
            }

            static::assertSafeUrl($url, false);

            return;
        }

        throw new \RuntimeException(__('databackd-data-sources.errors.invalid_url'));
    }

    /**
     * SSRF guard: validates a URL that a user supplied (or a redirect target) and returns its normalized form
     * (lower-case host without a trailing dot, no port, no fragment).
     *
     * Refused: any scheme but https, a port other than 443, user:pass@, backslashes/spaces/control or non-ASCII characters,
     * IP literals in any notation (decimal, hex, octal, IPv6, IPv4-mapped), single-label hosts (localhost) and every host
     * that is not exactly docs.google.com (for a redirect: docs.google.com or *.googleusercontent.com).
     * With $resolve the host is also resolved (A and AAAA) and every address must be public (isPublicIp()).
     *
     * @param bool $resolve false = purely syntactic check without DNS (no network needed)
     * @param bool $redirect true = $url is a redirect target (wider host allowlist)
     * @throws \RuntimeException with a translated message
     */
    public static function assertSafeUrl(string $url, bool $resolve = true, bool $redirect = false): string
    {
        $target = static::inspectUrl($url, $redirect);

        if ($resolve) {
            static::resolvePublicIps($target['host']);
        }

        return $target['url'];
    }

    /**
     * Whether an IP address is public (safe to connect to): not private, loopback, link-local (169.254.0.0/16, cloud metadata),
     * carrier-grade NAT (100.64.0.0/10), reserved, documentation, multicast, unique-local (fc00::/7), IPv4-mapped/NAT64/6to4 IPv6.
     * Anything that is not a canonical IPv4/IPv6 address string returns false.
     */
    public static function isPublicIp(string $ip): bool
    {
        $bin = @inet_pton(trim($ip));

        if ($bin === false) {
            return false;
        }

        if (strlen($bin) === 4) {
            foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
                '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
                '224.0.0.0/4', '240.0.0.0/4'] as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // IPv6: only global unicast (2000::/3) qualifies - this already excludes ::, ::1, ::ffff:0:0/96 (IPv4-mapped), 64:ff9b::/96 (NAT64),
        // fc00::/7, fe80::/10 and ff00::/8; the special blocks inside 2000::/3 are removed explicitly.
        if (!self::inCidr($bin, '2000::/3')) {
            return false;
        }

        foreach (['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'] as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fetch the CSV of a saved/new source by its type.
     *
     * @param string $type google_sheets|csv_url
     * @throws \RuntimeException
     */
    public function fetchForType(string $type, string $url, ?string $sheet = null): string
    {
        return $type === 'csv_url' ? $this->fetchCsvUrl($url) : $this->fetchCsv($url, $sheet);
    }

    /**
     * Download the sheet and parse it.
     *
     * @return array{headers: array<int,string>, rows: array<int,array<int,mixed>>}
     * @throws \RuntimeException
     */
    public function read(string $url, ?string $gid = null): array
    {
        return $this->importer()->readCsvString($this->fetchCsv($url, $gid));
    }

    /**
     * Download a CSV URL and parse it (see fetchCsvUrl()).
     *
     * @return array{headers: array<int,string>, rows: array<int,array<int,mixed>>}
     * @throws \RuntimeException
     */
    public function readCsvUrl(string $url): array
    {
        return $this->importer()->readCsvString($this->fetchCsvUrl($url));
    }

    /**
     * Whether files of public/databackd/templates may be read directly from disk (demo without internet).
     * This is the ONLY thing the local mode enables: it never relaxes the URL/SSRF rules.
     */
    public function canReadLocally(): bool
    {
        return static::isLocalDevelopment();
    }

    /**
     * Local development environment, decided by a trusted signal only: the host of APP_URL as read from the environment at boot
     * (config app.main_app_url; production: https://databackd.com). NOT used, because an attacker or a setting controls them:
     * the request host / config app.url (AFV overwrites it on every request from url('/'), i.e. from the Host header),
     * config app.debug (AFV overwrites it from the DB setting) and APP_ENV (always "codecanyon").
     */
    public static function isLocalDevelopment(): bool
    {
        $host = parse_url((string)config('app.main_app_url'), PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }

    // ------------------------------------------------------------------ internals

    protected function importer(): FinEntryImporter
    {
        return new FinEntryImporter($this->companyId);
    }

    protected function normalizeGid(?string $gid): ?string
    {
        $gid = trim((string)$gid);

        if ($gid === '') {
            return null;
        }

        if (preg_match('~gid=(\d+)~', $gid, $matches)) {
            return $matches[1];
        }

        return preg_match('~^\d+$~', $gid) ? $gid : null;
    }

    /**
     * Absolute path of a public/databackd/templates file for the accepted URL forms, or null when the URL is not such a local reference.
     */
    protected function localTemplatePath(string $url): ?string
    {
        $prefix = 'public/' . self::LOCAL_TEMPLATES_DIR . '/';
        $path = null;

        if (str_starts_with($url, $prefix)) {
            $path = substr($url, strlen($prefix));
        }
        elseif (str_starts_with($url, '/' . self::LOCAL_TEMPLATES_DIR . '/')) {
            $path = substr($url, strlen(self::LOCAL_TEMPLATES_DIR) + 2);
        }
        elseif (preg_match('~^https?://[^/]+/' . preg_quote(self::LOCAL_TEMPLATES_DIR, '~') . '/(.+)$~i', $url, $matches)) {
            $path = $matches[1];
        }

        if ($path === null) {
            return null;
        }

        // drop query/fragment; only a plain *.csv file name inside the templates folder is allowed (whitelist, no traversal)
        $path = preg_split('~[?#]~', $path)[0];
        $name = basename(rawurldecode($path));

        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*\.csv$~', $name) || str_contains($name, '..')) {
            return null;
        }

        return public_path(self::LOCAL_TEMPLATES_DIR . '/' . $name);
    }

    // ------------------------------------------------------------------ SSRF guard internals

    // AFV (S10): every reader message comes from databackd-data-sources.errors.* (eng + bg); the former
    // fallback helper (databackd-misc.sheets.* / hard-coded Bulgarian text) was removed.
    protected static function notAllowed(bool $redirect): \RuntimeException
    {
        return new \RuntimeException($redirect
            ? __('databackd-data-sources.errors.redirect_blocked')
            : __('databackd-data-sources.errors.url_not_allowed'));
    }

    /**
     * Syntactic part of the guard (no DNS). Parses the URL by hand (the result must also agree with parse_url()) so no
     * parser differential can smuggle another host through.
     *
     * @return array{url: string, host: string, path: string} normalized URL, host and path+query
     * @throws \RuntimeException
     */
    protected static function inspectUrl(string $url, bool $redirect = false): array
    {
        $url = trim($url);

        // only printable ASCII: no spaces, control characters (CR/LF/NUL), Unicode look-alike dots or IDN hosts; no backslashes
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('~[^\x21-\x7e]~', $url) || str_contains($url, '\\')) {
            throw static::notAllowed($redirect);
        }

        // the fragment never reaches the server
        if (!preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://([^/?#]*)([^#]*)~', $url, $m) || strtolower($m[1]) !== 'https') {
            throw static::notAllowed($redirect);
        }

        [, , $authority, $rest] = $m;

        // no user:pass@, no IPv6 literal ([::1]); at most ":443"
        if ($authority === '' || str_contains($authority, '@') || str_starts_with($authority, '[')) {
            throw static::notAllowed($redirect);
        }

        $host = $authority;

        if (str_contains($authority, ':')) {
            [$host, $port] = explode(':', $authority, 2);

            if (!preg_match('~^\d{1,5}$~', $port) || (int)$port !== 443) {
                throw static::notAllowed($redirect);
            }
        }

        // canonical host: lower-case, one trailing dot dropped; letters/digits/hyphen labels, at least two labels and an alphabetic TLD
        // (this rejects 127.0.0.1, 0x7f.1, 2130706433, 017700000001, localhost and every other IP/hex/octal notation)
        $host = strtolower($host);

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if (strlen($host) > 253
            || !preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]*[a-z0-9]$~', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw static::notAllowed($redirect);
        }

        $allowed = $redirect ? self::ALLOWED_REDIRECT_HOSTS : self::ALLOWED_HOSTS;
        $matches = false;

        foreach ($allowed as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1); // ".googleusercontent.com"
                $matches = $matches || (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix));
            }
            else {
                $matches = $matches || $host === $pattern;
            }
        }

        if (!$matches) {
            throw static::notAllowed($redirect);
        }

        if ($rest === '' || $rest[0] === '?') {
            $rest = '/' . $rest;
        }

        $normalized = 'https://' . $host . $rest;
        $parsed = parse_url($normalized);

        if ($parsed === false || ($parsed['scheme'] ?? '') !== 'https' || strtolower($parsed['host'] ?? '') !== $host
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['port'])) {
            throw static::notAllowed($redirect);
        }

        return ['url' => $normalized, 'host' => $host, 'path' => $rest];
    }

    /**
     * Resolve the host (A and AAAA) and require EVERY address to be public. Returns the validated addresses; the connection is
     * pinned to them (CURLOPT_RESOLVE), so a second DNS answer (DNS rebinding) is never used.
     *
     * @return array<int,string>
     * @throws \RuntimeException
     */
    protected static function resolvePublicIps(string $host): array
    {
        $ips = [];

        foreach (static::lookup($host) as $ip) {
            $bin = @inet_pton((string)$ip);

            if ($bin === false || !self::isPublicIp((string)$ip)) {
                throw static::notAllowed(false);
            }

            $ips[inet_ntop($bin)] = true;
        }

        if (!$ips) {
            throw new \RuntimeException(__('databackd-data-sources.errors.network'));
        }

        return array_keys($ips);
    }

    /**
     * DNS lookup of A and AAAA records through the system resolver. Overridable (tests).
     *
     * @return array<int,string>
     */
    protected static function lookup(string $host): array
    {
        $ips = [];
        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        if (function_exists('dns_get_record')) {
            $v6 = @dns_get_record($host, DNS_AAAA);

            foreach (is_array($v6) ? $v6 : [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /** Whether the binary address $bin (4 or 16 bytes) lies in the CIDR block "addr/bits". */
    protected static function inCidr(string $bin, string $cidr): bool
    {
        [$addr, $bits] = explode('/', $cidr);
        $base = inet_pton($addr);
        $bits = (int)$bits;

        if ($base === false || strlen($base) !== strlen($bin)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($bin[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }

    /** Absolute URL of a Location header (relative forms are resolved against the URL that redirected). */
    protected static function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);

        if ($location === '' || preg_match('~[^\x21-\x7e]~', $location) || str_contains($location, '\\')) {
            throw static::notAllowed(true);
        }

        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*:~', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = 'https://' . ($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        if ($location[0] === '/') {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';

        if ($location[0] === '?') {
            return $origin . $path . $location;
        }

        return $origin . rtrim(substr($path, 0, (int)strrpos($path, '/') + 1), '/') . '/' . $location;
    }

    /**
     * One HTTPS GET without following redirects. The connection goes only to the validated $ips, the body is capped (MAX_BYTES,
     * counted after decompression) and the whole request by timeouts. Overridable (tests).
     *
     * @param array<int,string> $ips validated public addresses of $host
     * @return array{status: int, headers: array<string,string>, body: string}
     * @throws \RuntimeException
     */
    protected function requestOnce(string $url, string $host, array $ips): array
    {
        $network = fn() => new \RuntimeException(__('databackd-data-sources.errors.network'));

        if (!function_exists('curl_init')) {
            throw $network();
        }

        $status = 0;
        $headers = [];
        $body = '';
        $size = 0;
        $tooLarge = false;
        $max = static::MAX_BYTES;

        // prefer IPv4 (hosting without a working IPv6 route); IPv6 only when the host has no A record
        $v4 = array_values(array_filter($ips, fn($ip) => !str_contains($ip, ':')));
        $pin = array_map(fn($ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $v4 ?: $ips);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . implode(',', $pin)],
            CURLOPT_PROXY => '', // a proxy would connect on its own and bypass the pinned, validated addresses
            CURLOPT_CONNECTTIMEOUT => static::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => static::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => $max,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'databackd-import/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/csv,text/plain,*/*'],
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$status, &$headers, &$tooLarge, $max): int {
                $length = strlen($line);
                $line = trim($line);

                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                    $status = (int)$m[1];
                    $headers = [];
                }
                elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $headers[$name] = trim($value);

                    if ($name === 'content-length' && (float)trim($value) > $max) {
                        $tooLarge = true;

                        return 0; // abort the transfer
                    }
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$size, &$tooLarge, $max): int {
                $length = strlen($chunk);
                $size += $length;

                if ($size > $max) {
                    $tooLarge = true;

                    return 0; // abort the transfer
                }

                $body .= $chunk;

                return $length;
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $peer = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        unset($ch);

        // 63 = CURLE_FILESIZE_EXCEEDED (libcurl compared Content-Length with CURLOPT_MAXFILESIZE before our callbacks ran)
        if ($tooLarge || $errno === 63) {
            throw new \RuntimeException(__('databackd-data-sources.errors.too_large', ['max' => max(1, intdiv(static::MAX_BYTES, 1048576))]));
        }

        if ($ok === false) {
            throw $network();
        }

        // the address that was really connected to must be one of the validated ones
        $peerBin = @inet_pton($peer);

        if ($peerBin === false || !in_array(inet_ntop($peerBin), $ips, true)) {
            throw $network();
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Message for a refused download (401/403 or a redirect to the sign-in page). A "published to the web" link (/d/e/...) answers 401
     * when the spreadsheet or that tab is not (or no longer) published, which is a different fix than the sharing setting.
     */
    protected function forbiddenMessage(string $url, bool $googleSheet, int $status): string
    {
        if (!$googleSheet) {
            return __('databackd-data-sources.errors.csv_forbidden', ['status' => $status]);
        }

        return __(str_contains($url, '/spreadsheets/d/e/') ? 'databackd-data-sources.errors.not_published' : 'databackd-data-sources.errors.not_public');
    }

    /**
     * @param bool $googleSheet true: translate failures into "the sheet is not public" messages
     */
    protected function download(string $url, bool $googleSheet): string
    {
        $current = $url;
        $redirects = 0;

        // Redirects are followed by hand: every hop is validated and its DNS answer checked and pinned like the first request.
        while (true) {
            $target = static::inspectUrl($current, $redirects > 0);
            $ips = static::resolvePublicIps($target['host']);
            $response = $this->requestOnce($target['url'], $target['host'], $ips);
            $status = $response['status'];

            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                break;
            }

            if (++$redirects > static::MAX_REDIRECTS) {
                throw new \RuntimeException(__('databackd-data-sources.errors.too_many_redirects'));
            }

            $current = static::resolveLocation($target['url'], (string)($response['headers']['location'] ?? ''));

            // A private sheet redirects to the Google sign-in page: report it without requesting that page.
            $nextHost = strtolower(rtrim((string)parse_url($current, PHP_URL_HOST), '.'));

            if (in_array($nextHost, self::SIGN_IN_HOSTS, true)) {
                throw new \RuntimeException($this->forbiddenMessage($url, $googleSheet, $status));
            }
        }

        if (in_array($status, [401, 403], true)) {
            throw new \RuntimeException($this->forbiddenMessage($url, $googleSheet, $status));
        }

        // Google answers 400 (googleusercontent.com) when the gid does not exist in the spreadsheet
        if ($status === 404 || ($googleSheet && $status === 400)) {
            throw new \RuntimeException(__($googleSheet ? 'databackd-data-sources.errors.not_found' : 'databackd-data-sources.errors.csv_not_found'));
        }

        if ($status === 429) {
            throw new \RuntimeException(__('databackd-data-sources.errors.remote_rate_limited'));
        }

        if ($status < 200 || $status > 299) {
            throw new \RuntimeException(__('databackd-data-sources.errors.http_status', ['status' => $status]));
        }

        $body = $response['body'];
        $contentType = strtolower(trim(explode(';', (string)($response['headers']['content-type'] ?? ''))[0]));

        // Only CSV/text answers are accepted (a web page, JSON, XML, an image... is not CSV). A private sheet may also answer 200 with the HTML sign-in page.
        $csvTypes = ['', 'text/csv', 'text/plain', 'text/comma-separated-values', 'text/x-comma-separated-values', 'text/x-csv',
            'application/csv', 'application/x-csv', 'application/vnd.ms-excel', 'application/octet-stream', 'binary/octet-stream'];

        if ($contentType === 'text/html' || preg_match('~^\s*(<!doctype html|<html)~i', substr($body, 0, 200))) {
            throw new \RuntimeException(__($googleSheet ? 'databackd-data-sources.errors.not_public' : 'databackd-data-sources.errors.not_csv', ['status' => 200]));
        }

        if (!in_array($contentType, $csvTypes, true) || str_starts_with($body, "PK\x03\x04")) {
            throw new \RuntimeException(__('databackd-data-sources.errors.not_csv'));
        }

        if (trim($body) === '') {
            throw new \RuntimeException(__('databackd-data-sources.errors.empty_sheet'));
        }

        // the importer parses the whole text into arrays: cap the number of lines too
        if (max(substr_count($body, "\n"), substr_count($body, "\r")) > static::MAX_LINES) {
            throw new \RuntimeException(__('databackd-data-sources.errors.too_many_rows', ['max' => number_format(static::MAX_LINES, 0, '', ' ')]));
        }

        return $body;
    }

}
