<?php
/**
 * MyInvoice.cz — VZOROVÁ konfigurace.
 *
 * Postup:
 *   1. Zkopíruj tento soubor jako cfg.php (`cp cfg.sample.php cfg.php`)
 *   2. Vyplň reálné hodnoty (DB heslo, SMTP, Cloudflare Turnstile, IP allowlist, …)
 *   3. cfg.php je v .gitignore — NIKDY ho necommituj
 *
 * Per-environment override můžeš dát do cfg.local.php (taky gitignored).
 *
 * Docker / kontejnerový deploy: stačí prázdný `<?php return [];` a všechny
 * citlivé hodnoty předat přes ENV (viz envOverrideMap v Config.php). Volitelná
 * ENV `MYINVOICE_DATA_DIR=/data` přesune VŠECHNY stateful adresáře (log/,
 * storage/{invoices,uploads,backup,sessions,cache}, private/dkim/) pod jediný
 * volume — zbytek kontejneru pak může běžet read-only a image updaty jsou
 * bezbolestné. `${MYINVOICE_DATA_DIR}/cfg.local.php` se navíc auto-loaduje,
 * takže per-instance konfigurace přežije image update.
 */

return [
    'app' => [
        'env'    => 'production',                    // 'development' | 'production' (řídí debug výpisy, error reporting). Nikdy nedávat 'development' na veřejně dostupný server.
        'debug'  => false,                           // false v produkci — skryje stack trace v API odpovědích
        'url'    => 'https://dev.example.com',       // veřejná URL aplikace, používá se v emailech (odkazy na faktury, reset hesla)
        'pepper' => 'CHANGE-ME',                     // doplňková sůl k password_hash, 32B base64: openssl rand -base64 32
        'secret_encryption_key' => '',               // 32B base64 pro AES-256-GCM (TOTP secrets); openssl rand -base64 32. Pokud prázdné, fallback HKDF z pepperu.
        'timezone' => 'Europe/Prague',               // PHP date_default_timezone_set
        'locale_default' => 'cs',                    // jazyk UI při prvním načtení (před přihlášením)
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'myinvoice',
        'user'    => 'root',
        'pass'    => 'CHANGE-ME',
        'charset' => 'utf8mb4',
        'socket'  => null,                           // unix socket path (nebo null pro TCP); na Windows vždy null
        'dump_tool' => '',                           // absolutní cesta k mariadb-dump / mysqldump pro cron-backup.php. Prázdné = auto-detekce z PATH a běžných instalačních lokací (Win: C:\Program Files\MariaDB*\bin, C:\inetpub\MariaDB\bin, XAMPP, Laragon).
        'backup_skip_routines' => false,             // true = vynechat stored procedures/functions ze zálohy. Dej true pokud DB user nemá privilege a nechceš grantovat (např. shared hosting). Default false = včetně procedur; při permission erroru auto-fallback s warningem.
    ],
    'redis' => [
        'enabled' => false,                           // false = fallback na DB sessions a in-memory cache
        'host'    => '127.0.0.1',
        'port'    => 6379,
        'auth'    => null,                           // heslo nebo null
        'db'      => 0,                              // číslo Redis databáze 0..15
        'prefix'  => 'myinvoice:dev:',               // namespace všech klíčů — změň na 'myinvoice:prod:' v produkci
    ],
    'session' => [
        'driver'        => 'auto',                   // 'auto' = Redis pokud běží, jinak DB | 'redis' | 'db'
        'lifetime_days' => 30,                       // platnost cookie i serverové session
        'cookie_name'   => '__Host-myinvoice_session', // __Host- prefix → vyžaduje Secure + Path=/ + bez Domain (přísnější CSRF). Pro HTTP dev změnit na 'myinvoice_session' a cookie_secure=false.
        'cookie_secure' => true,                     // true vyžaduje HTTPS — false jen pro lokální HTTP dev (a __Host- nebude fungovat)
        'cookie_samesite' => 'Lax',                  // 'Lax' | 'Strict' | 'None' (None vyžaduje secure=true)
    ],
    'smtp' => [
        // Connection
        'host'           => 'smtp.example.com',
        'port'           => 587,                     // 25 plain | 465 SSL | 587 STARTTLS
        'encryption'     => 'tls',                   // 'ssl' | 'tls' | '' (žádné, jen lokální dev nebo plain relay)

        // Authentication
        'auth_enabled'   => false,                    // false pro plain relay (např. interní MTA bez auth)
        'auth_type'      => 'PLAIN',                 // 'LOGIN' | 'PLAIN' | 'CRAM-MD5' | 'XOAUTH2'
        'user'           => 'CHANGE-ME',
        'pass'           => 'CHANGE-ME',

        // OAuth2 (jen pokud auth_type='XOAUTH2', např. Gmail/M365 moderní auth)
        'oauth' => [
            'provider'      => null,                 // 'google' | 'microsoft' | null
            'client_id'     => '',
            'client_secret' => '',
            'refresh_token' => '',
        ],

        // Sender identity
        'from_email'     => 'noreply@example.com',   // adresa v hlavičce From: (musí být na doméně, kde máš DKIM/SPF)
        'from_name'      => 'MyInvoice',             // zobrazované jméno odesílatele
        'reply_to_email' => '',                      // pokud prázdné, použije se from_email
        'reply_to_name'  => '',

        // Při odeslání faktury klientovi přidá supplier.email (z Nastavení > Dodavatel)
        // do CC. Hlavní To = client_main_email + project_billing_emails (vždy).
        'cc_supplier_on_send'     => false,
        // Stejné CC pro upomínky (ruční i z cronu, vč. proforma_reminder).
        // Většinou nechcete sobě chodit kopie každé odeslané upomínky → default false.
        'cc_supplier_on_reminder' => false,

        // TLS validation
        'verify_peer'      => true,                  // ověřit cert serveru — vypnout JEN pro self-signed dev SMTP
        'verify_peer_name' => true,
        'allow_self_signed'=> false,

        // Behavior
        'timeout'        => 30,                      // socket timeout (s)
        'keepalive'      => false,                   // true = jeden persistent connection pro batch (rychlejší pro N emailů)
        'charset'        => 'UTF-8',
        'encoding'       => '8bit',                  // '7bit' | '8bit' | 'base64' | 'quoted-printable'
        'wordwrap'       => 78,                      // line wrap (RFC 5322 doporučuje 78)

        // Anti-spam / deliverability — DKIM podpis odchozí pošty
        //
        // Klíče dej do /private/dkim/ (gitignored). Doména DKIM = doména z from_email.
        // Selector: myinvoice → DNS hostname:  myinvoice._domainkey.{domain}
        // Po publikaci DNS TXT záznamu přepnout enabled => true.
        'dkim' => [
            'enabled'         => false,
            'domain'          => 'example.com',
            'selector'        => 'myinvoice',
            'passphrase'      => '',                                          // pokud je private key šifrovaný
            'private_key_path'=> __DIR__ . '/private/dkim/myinvoice.pem',
            'public_key_path' => __DIR__ . '/private/dkim/myinvoice.pub',
            'dns_doc_path'    => __DIR__ . '/private/dkim/dns.txt',           // návod na DNS nastavení (SPF/DKIM/DMARC)
        ],

        // Debug & monitoring (jen pro lokální dev, v produkci vždy 0)
        'debug_level'    => 0,                       // 0=off, 1=client, 2=client+server, 3=+connection, 4=low-level
        'debug_log_file' => '',                      // pokud nastaven, debug se loguje do souboru místo stderr

        // Retry & queueing
        'max_retries'    => 3,                       // počet retry pokud SMTP odmítne (4xx soft-fail)
        'retry_delay_s'  => 60,                      // pauza mezi retry
    ],
    'ares' => [
        'api'       => 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty',
        'cache_ttl' => 86400,                        // 24h cache odpovědí ARES (per IČ)
        'timeout'   => 5,                            // HTTP timeout v sekundách
    ],
    'vies' => [
        // REST API (preferováno, jednodušší než SOAP). Pattern: /ms/{COUNTRY}/vat/{VAT_NUMBER_BEZ_PREFIXU}
        'rest_api'  => 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms',
        // SOAP fallback pokud REST vypadne
        'wsdl'      => 'http://ec.europa.eu/taxation_customs/vies/services/checkVatService.wsdl',
        'cache_ttl' => 10800,                        // 3h cache odpovědí VIES (per DIČ) — VIES občas vrací false-negative při výpadku, krátká cache omezí dopad
        'timeout'   => 8,
    ],
    'logging' => [
        'level'    => 'info',                        // debug | info | notice | warning | error
        'path'     => __DIR__ . '/log/app.log',
        'max_files'=> 90,                            // rotace: drž posledních N denních souborů
    ],
    'storage' => [
        'invoices_dir' => __DIR__ . '/storage/invoices',  // vygenerovaná PDF
        'uploads_dir'  => __DIR__ . '/storage/uploads',   // dočasné upload soubory (GPC výpisy, attachementy)
        'backup_dir'   => __DIR__ . '/storage/backup',    // mysqldump zálohy z cron-backup.php
        'sessions_dir' => __DIR__ . '/storage/sessions',  // jen pokud session.driver = 'db' (file fallback)
        'cache_dir'    => __DIR__ . '/storage/cache',     // file cache (ARES/VIES odpovědi, PDF mezikroky)
    ],
    'qr' => [
        'czk_constant_symbol' => '0308',             // KS pro CZK platby (0308 = běžný platební styk)
    ],
    'pagination' => [
        // Velikost stránky pro tlačítko "Další" v UI seznamech.
        // Min/max hranice (5 / 200) se enforcují v API; mimo rozsah API hodnotu clampuje.
        'invoices_per_page' => 50,
        'clients_per_page'  => 50,
        'projects_per_page' => 50,
    ],
    'varsymbol' => [
        // Template pro var. symbol per typ dokladu.
        // Placeholdery:
        //   {YYYY} = rok (4 číslice, např. 2026)
        //   {YY}   = rok (2 číslice, např. 26)
        //   {MM}   = měsíc (2 číslice, 01..12)
        //   {C}    = counter v rámci měsíce + typu, padded podle počtu znaků (CCC = 001..999)
        // Counter se reset na 1 každý měsíc, samostatně per typ dokladu.
        'templates' => [
            'invoice'     => '{YY}{MM}{CCC}',        // např. 2604001
            'proforma'    => '9{YY}{MM}{CCC}',       // např. 92604001 (prefix 9 = záloha)
            'credit_note' => '7{YY}{MM}{CCC}',       // např. 72604001 (prefix 7 = dobropis)
        ],
    ],
    'rate_limits' => [
        // Limity nad rámec brute_force (ten řeší jen login). Klíč = max požadavků v daném okně.
        'login_per_min_per_ip'      => 10,           // doplněk k brute_force; ochrana proti spamu z 1 IP
        'forgot_per_hour_per_email' => 3,            // POST /auth/forgot-password
        'mutation_per_min_per_user' => 60,           // všechna POST/PUT/PATCH/DELETE
        'read_per_min_per_user'     => 300,          // GET endpointy
        'ares_per_min_per_user'     => 30,           // proxy na ARES (cachované, ale brzdí abuse)
        'setup_per_hour_per_ip'     => 5,            // /setup wizard endpoint
    ],
    'brute_force' => [
        // Progresivní obrana login formuláře. Počítá selhání per (email, IP) v posuvných oknech.
        'captcha_after'   => 5,                      // selhání / 5 min  → vyžadovat CAPTCHA
        'lockout_15m_at'  => 10,                     // selhání / 15 min → lockout 15 min
        'lockout_24h_at'  => 30,                     // selhání / 60 min → lockout 24h
        'window_seconds'  => [300, 900, 3600],       // okna v sekundách: 5 min, 15 min, 60 min
    ],
    'captcha' => [
        'provider'    => 'none',                // 'turnstile' (Cloudflare) | 'none' (vypnout)
        'site_key'    => 'CHANGE-ME',                // public, vkládá se do HTML <div data-sitekey="...">
        'secret_key'  => 'CHANGE-ME',                // server-side verify — NIKDY do frontend bundle
        'verify_url'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'script_url'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
        'timeout'     => 5,                          // siteverify HTTP timeout (s)
        'fail_open'   => true,                       // pokud Cloudflare API timeoutuje: true=povolit login, false=odmítnout
        'action'      => 'login',                    // pro Turnstile analytics, lze rozlišit per route
    ],
    'approval' => [
        // Schvalování výkazu zákazníkem přes veřejný odkaz s tokenem.
        'token_ttl_days'        => 30,               // za kolik dní token vyprší (přesměrovat „odeslat znovu" v UI)
        'reminder_after_days'   => 5,                // cron: kolik dní bez reakce → poslat upomínku
        'max_reminders'         => 3,                // max počet upomínek na 1 token, pak přestat
        'cc_supplier_on_approval'          => true,  // BCC dodavateli u první žádosti o schválení (audit)
        'cc_supplier_on_approval_reminder' => true,  // BCC dodavateli u schvalovacích upomínek (audit)
    ],
    'ip_allowlist' => [
        // Volitelný IP firewall na úrovni aplikace (mimo Apache/IIS).
        'enabled' => false,                          // true = blokovat mimo allow seznam
        'mode'    => 'block',                        // 'block' = vrátit 403 | 'log_only' = jen logovat (audit fáze)
        'allow'   => [
            // '127.0.0.1',
            // '192.168.1.0/24',
            // '2001:db8::/56',
        ],
        'apply_to' => 'all',                         // 'all' = celá app | 'admin_only' = jen /admin/* | 'mutations_only' = jen POST/PUT/PATCH/DELETE
        'trusted_proxies' => [                       // CIDR proxy, jejichž X-Forwarded-For je důvěryhodný (Cloudflare ranges atd.)
            // '10.0.0.0/8',
        ],
        'header' => 'X-Forwarded-For',               // hlavička se skutečnou klient IP (přepíše REMOTE_ADDR za trusted proxy)
    ],

    // Auto-import bankovních výpisů (GPC/ABO) z monitorovaného adresáře.
    // Manuální upload přes UI funguje vždy bez ohledu na tuto sekci.
    'bank_import' => [
        'scan_root'      => '',                      // absolutní cesta (např. 'C:/Users/me/FIO/exports'); prázdné = scan vypnutý
        'allowed_exts'   => ['gpc', 'txt'],          // jen tyto přípony se zpracují
        'auto_match'     => true,                    // automatické párování transakcí na faktury podle VS
        'partial_match_tolerance' => 1.00,           // částečná shoda částky: 1.00 = jen přesně, 0.99 = ±1%, 0.0 = jakákoliv částka
    ],

    // Cron retention (api/bin/cron-cleanup.php + cron-backup.php)
    'cron' => [
        'cleanup' => [
            'login_attempts_hours' => 24,            // po N hodinách smaž záznamy z login_attempts
            'password_resets_days' => 7,             // po N dnech smaž expirované reset tokeny
            'cache_ttl_days'       => 30,            // po N dnech smaž záznamy v cache (ARES/VIES)
            'pdf_cache_days'       => 90,            // po N dnech smaž generované PDF (regenerují se on-demand)
        ],
        'backup' => [
            'daily_retention_days'   => 30,          // drž denní mysqldump zálohy N dnů
            'monthly_retention_days' => 365,         // drž 1. v měsíci jako "monthly" zálohu N dnů
            'output_dir'             => 'storage/backup',
        ],
    ],
];
