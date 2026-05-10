<?php

declare(strict_types=1);

namespace MyInvoice\I18n;

/**
 * Bilingual katalog backend chybových hlášek.
 *
 * Klíč = literální CZ text (přesně jak ho píše Json::error). Hodnota = EN ekvivalent.
 *
 * Chybový text s PHP proměnnými ($var, {$var}) sem nepatří — zůstává v CZ
 * (na seznamu výjimek, ~10 ks; lze upgradovat na placeholder formát kdykoli).
 *
 * Json::error() projde každý message přes lookup() — pokud nenajde, vrátí původní text.
 */
final class ErrorCatalog
{
    /** @var array<string,string> CZ → EN */
    private const MAP = [
        'ARES je dočasně nedostupný.' => 'ARES is temporarily unavailable.',
        'Aktuální heslo není správné.' => 'Current password is incorrect.',
        'Aplikace ještě není inicializovaná. Otevřete /setup pro vytvoření admin účtu.' => 'Application is not initialized yet. Open /setup to create the admin account.',
        'Chybí invoice_id.' => 'Missing invoice_id.',
        'Chybí project_id.' => 'Missing project_id.',
        'Chybí title.' => 'Missing title.',
        'Chyba konfigurace serveru.' => 'Server configuration error.',
        'DIČ musí mít prefix země a 4-12 číslic (např. CZ12345678).' => 'VAT ID must have a country prefix and 4–12 digits (e.g. CZ12345678).',
        'Dobropis ani storno nelze stornovat.' => 'Credit note and cancellation cannot be cancelled.',
        'Email je už registrovaný.' => 'Email is already registered.',
        'Email se nepodařilo odeslat: ' => 'Failed to send email: ',
        'Faktura byla mezitím změněna.' => 'Invoice has been modified in the meantime.',
        'Faktura musí obsahovat alespoň jednu položku.' => 'Invoice must contain at least one item.',
        'Hesla se neshodují.' => 'Passwords do not match.',
        'Heslo musí mít alespoň 12 znaků.' => 'Password must be at least 12 characters.',
        'Interní storno se klientovi neposílá.' => 'Internal cancellation is not sent to the client.',
        'IČ musí mít 8 číslic.' => 'Reg. No. must have 8 digits.',
        'Jméno je povinné.' => 'Name is required.',
        'Lze označit jako zaplacené jen vystavenou nebo odeslanou fakturu.' => 'Only an issued or sent invoice can be marked as paid.',
        'Lze poslat jen vystavenou fakturu.' => 'Only an issued invoice can be sent.',
        'Lze pouze ze zálohové faktury (proforma).' => 'Only allowed from a proforma invoice.',
        'Lze smazat jen draft fakturu (vystavenou jen storno/dobropis).' => 'Only a draft invoice can be deleted (issued ones only via cancel/credit note).',
        'Lze vystavit jen draft fakturu.' => 'Only a draft invoice can be issued.',
        'Lze zrušit jen vystavenou/odeslanou/zaplacenou fakturu.' => 'Only an issued/sent/paid invoice can be cancelled.',
        'Měna nenalezena.' => 'Currency not found.',
        'Měna s tímto kódem už existuje.' => 'A currency with this code already exists.',
        'Nelze deaktivovat posledního aktivního admina.' => 'Cannot deactivate the last active admin.',
        'Nelze odebrat admin roli ani deaktivovat posledního aktivního admina.' => 'Cannot remove the admin role or deactivate the last active admin.',
        'Nelze parsovat: ' => 'Cannot parse: ',
        'Nelze smazat vlastní účet.' => 'Cannot delete your own account.',
        'Nelze vytvořit ZIP.' => 'Cannot create ZIP.',
        'Není vybrána žádná faktura.' => 'No invoice selected.',
        'Neplatná role.' => 'Invalid role.',
        'Neplatné datum.' => 'Invalid date.',
        'Neplatné přihlašovací údaje.' => 'Invalid credentials.',
        'Neplatný email.' => 'Invalid email.',
        'Neplatný kód měny.' => 'Invalid currency code.',
        'Neplatný nebo chybějící CSRF token.' => 'Invalid or missing CSRF token.',
        'Neplatný token.' => 'Invalid token.',
        'Nepodařilo se vygenerovat PDF: ' => 'Failed to generate PDF: ',
        'Nepřihlášený uživatel.' => 'Not authenticated.',
        'Nová hesla se neshodují.' => 'New passwords do not match.',
        'Origin nesedí s app URL.' => 'Origin does not match the app URL.',
        'Parametr month musí být YYYY-MM.' => 'Parameter "month" must be YYYY-MM.',
        'Platnost tokenu vypršela.' => 'Token has expired.',
        'Pouze admin nebo účetní.' => 'Admin or accountant only.',
        'Proforma musí být označená jako zaplacená.' => 'Proforma must be marked as paid.',
        'Příliš mnoho pokusů. Zkus to později.' => 'Too many attempts. Try again later.',
        'Setup již proběhl.' => 'Setup has already been completed.',
        'Soubor chybí.' => 'File missing.',
        'Soubor je prázdný.' => 'File is empty.',
        'Storno doklad nelze editovat.' => 'A cancellation document cannot be edited.',
        'Storno nedostává varsymbol.' => 'A cancellation document does not get a variable symbol.',
        'Supplier nevyplněn (spusť setup).' => 'Supplier not configured (run setup).',
        'Tato IP adresa nemá přístup k aplikaci.' => 'This IP address is not allowed to access the application.',
        'TOTP už je aktivní. Pro reset použij: php api/bin/reset-2fa.php <email>.' => 'TOTP is already enabled. To reset it, use: php api/bin/reset-2fa.php <email>.',
        'Token nebo heslo chybí.' => 'Token or password missing.',
        'Token už byl použit.' => 'Token has already been used.',
        'Uživatel nenalezen.' => 'User not found.',
        'Vystavenou fakturu nelze editovat.' => 'An issued invoice cannot be edited.',
        'Vyžaduje se CAPTCHA.' => 'CAPTCHA required.',
        'Výkaz lze smazat pouze v draftu (admin: ?force=1).' => 'Work report can only be deleted on a draft (admin: ?force=1).',
        'Výkaz lze upravit pouze v draftu (admin: ?force=1).' => 'Work report can only be edited on a draft (admin: ?force=1).',
        'Výpis nenalezen.' => 'Bank statement not found.',
        'Zakázka nenalezena.' => 'Project not found.',
        'Země s tímto iso2 už existuje.' => 'A country with this iso2 already exists.',
        'Záloha nesmí být záporná.' => 'Advance payment must not be negative.',
        'cfg.bank_import.scan_root není nastaveno nebo adresář neexistuje.' => 'cfg.bank_import.scan_root is not set or the directory does not exist.',
        'cfg.smtp.from_email není nastaveno.' => 'cfg.smtp.from_email is not set.',
        'code a rate_percent jsou povinné.' => '"code" and "rate_percent" are required.',
        'code musí být 3 znaky.' => '"code" must be 3 characters.',
        'iso2 musí být 2 znaky.' => '"iso2" must be 2 characters.',
        'mode musí být "internal" nebo "credit_note".' => '"mode" must be "internal" or "credit_note".',
        'Žádný platný příjemce (chybí email klienta).' => 'No valid recipient (client email missing).',
        // Časté hlášky používané v jednom callsite, ale stejný text se opakuje (např. not_found):
        'Klient nenalezen.' => 'Client not found.',
        'Faktura nenalezena.' => 'Invoice not found.',
        'Validace selhala' => 'Validation failed',
    ];

    /**
     * Vrátí EN překlad pokud existuje v katalogu, jinak původní text.
     * Pro $locale = 'cs' (default) vrací vždy původní.
     *
     * Ošetřuje i prefix-match: pokud je v katalogu klíč "Email se nepodařilo odeslat: "
     * a vstup je "Email se nepodařilo odeslat: connection timeout", přeloží prefix.
     */
    public static function lookup(string $cs, string $locale): string
    {
        if ($locale === 'cs' || $cs === '') return $cs;
        if (isset(self::MAP[$cs])) return self::MAP[$cs];
        // Prefix-match pro hlášky končící zprávou výjimky
        foreach (self::MAP as $key => $en) {
            if (str_ends_with($key, ': ') && str_starts_with($cs, $key)) {
                return $en . substr($cs, strlen($key));
            }
        }
        return $cs;
    }
}
