<?php

namespace App\Services\Databackd\Gaps\BankImport;

/**
 * SWIFT MT940 customer statement parser.
 *
 * Tags: :20: reference, :25: account, :28C: statement number, :60F:/:60M: opening balance, :61: transaction,
 * :86: details of the transaction, :62F:/:62M: closing balance. Tolerant to CRLF, SWIFT blocks ({1:..}{4: ... -}),
 * lines that continue a tag, UTF-8 and Windows-1251 files and the three styles of :86: that banks use:
 * "?20..?32" subfields, "/NAME/../REMI/.." keys and free text ("Наредител: ..; Основание: ..").
 *
 * Returns ['statements' => [...], 'warnings' => [...]] - the statement structure is documented in BankStatementParser.
 */
class Mt940Parser
{

    const TAGS = '20|21|25P|25|28C|28|60F|60M|61|62F|62M|64|65|86|34F|13D|NS|90D|90C';

    /** Keys of the "/KEY/value" style of :86: */
    const SLASH_KEYS = 'EREF|BENM|ORDP|CNTP|REMI|NAME|IBAN|ADDR|BIC|ISDT|ACCW|OCMT|CHGS|EXCH|ULTD|ULTB|CRED|DEBT|MREF|KREF|SVWZ|ABWA|PREF|BREF|PURP|RTRN|CAUS|ORCR|ORMN|COAM|IPI|CSID|CNTR|ID';

    /** Labels of the counterparty in free text, by direction (money in => the payer, money out => the payee). */
    const LABELS_CREDIT = ['Наредител', 'Платец', 'От', 'Подател', 'Ordering party', 'Ordering customer', 'Order party', 'Payer', 'Sender', 'Debtor', 'From', 'Name'];
    const LABELS_DEBIT = ['Получател', 'Бенефициент', 'До', 'Beneficiary', 'Payee', 'Recipient', 'Creditor', 'To', 'Name'];

    public function parse(string $content): array
    {
        $text = BankStatementParser::toUtf8($content);
        $warnings = [];
        $statements = [];

        foreach ($this->messages($this->tokenize($text)) as $tokens) {
            $statement = $this->parseMessage($tokens, $warnings);

            if ($statement === null) {
                continue;
            }

            // a statement split into pages: :60M: opens a continuation of the previous page of the same account
            $last = count($statements) - 1;

            if ($statement['_opening_intermediate'] && $last >= 0
                && $statements[$last]['_closing_intermediate'] && $statements[$last]['account_iban'] === $statement['account_iban']) {
                $statements[$last]['transactions'] = array_merge($statements[$last]['transactions'], $statement['transactions']);
                $statements[$last]['closing_balance'] = $statement['closing_balance'];
                $statements[$last]['closing_date'] = $statement['closing_date'];
                $statements[$last]['_closing_intermediate'] = $statement['_closing_intermediate'];

                continue;
            }

            $statements[] = $statement;
        }

        foreach ($statements as $i => $statement) {
            $statements[$i] = $this->finish($statement);
        }

        return ['statements' => $statements, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ tokens and messages

    /**
     * @return array<int, array{tag?: string, value?: string, end?: bool}>
     */
    protected function tokenize(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $tokens = [];
        $index = null;

        foreach (explode("\n", $text) as $line) {
            // SWIFT header blocks: the text block starts after "{4:" (possibly on the same line as the first tag)
            if (ltrim($line) !== '' && ltrim($line)[0] === '{') {
                $pos = strpos($line, '{4:');

                if ($pos === false) {
                    continue;
                }

                $line = substr($line, $pos + 3);

                if (trim($line) === '') {
                    continue;
                }
            }

            $trimmed = trim($line);

            // AFV (S10): "-}" may be followed by the trailer block on the same line ("-}{5:{CHK:...}}"), which must not
            // be read as a continuation of the last tag
            if ($trimmed === '-' || $trimmed === '}' || str_starts_with($trimmed, '-}')) {
                $tokens[] = ['end' => true];
                $index = null;

                continue;
            }

            if (preg_match('/^\s*:(' . self::TAGS . '):(.*)$/s', rtrim($line), $m)) {
                $tokens[] = ['tag' => $m[1], 'value' => $m[2]];
                $index = count($tokens) - 1;

                continue;
            }

            if ($index !== null) {
                // trailing spaces are kept: a full-width line that ends with a space continues in the next line
                $tokens[$index]['value'] .= "\n" . $line;
            }
        }

        return $tokens;
    }

    /**
     * Tokens of one statement message each (a new :20: after the body of the previous message starts the next one).
     */
    protected function messages(array $tokens): array
    {
        $messages = [];
        $current = [];

        foreach ($tokens as $token) {
            if (isset($token['end'])) {
                if ($current) {
                    $messages[] = $current;
                }

                $current = [];

                continue;
            }

            if ($token['tag'] === '20' && $this->hasBody($current)) {
                $messages[] = $current;
                $current = [];
            }

            $current[] = $token;
        }

        if ($current) {
            $messages[] = $current;
        }

        return $messages;
    }

    protected function hasBody(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token['tag'], ['25', '25P', '60F', '60M', '61', '62F', '62M'], true)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ one statement

    protected function parseMessage(array $tokens, array &$warnings): ?array
    {
        $statement = [
            'format' => 'mt940',
            'reference' => null,
            'statement_no' => null,
            'account_iban' => null,
            'currency' => null,
            'date_from' => null,
            'date_to' => null,
            'opening_balance' => null,
            'opening_date' => null,
            'closing_balance' => null,
            'closing_date' => null,
            'transactions' => [],
            '_opening_intermediate' => false,
            '_closing_intermediate' => false,
        ];

        $accountCurrency = null;
        $last = null; // index of the last :61: that has no :86: yet
        $seenBody = false;

        foreach ($tokens as $token) {
            $value = $token['value'];
            $firstLine = trim(explode("\n", $value)[0]);

            switch ($token['tag']) {
                case '20':
                    $statement['reference'] = $firstLine !== '' ? mb_substr($firstLine, 0, 60) : null;
                    break;

                case '25':
                case '25P':
                    [$account, $accountCurrency] = Iban::fromAccountField($firstLine);
                    $statement['account_iban'] = $account;
                    $seenBody = true;
                    break;

                case '28':
                case '28C':
                    $statement['statement_no'] = $firstLine !== '' ? mb_substr($firstLine, 0, 60) : null;
                    break;

                case '60F':
                case '60M':
                    $balance = $this->balance($firstLine);

                    if ($balance === null) {
                        $warnings[] = __('databackd-gaps-bank-import.warnings.bad_balance', ['tag' => ':' . $token['tag'] . ':', 'value' => mb_substr($firstLine, 0, 40)]);
                        break;
                    }

                    $statement['opening_balance'] = $balance['amount'];
                    $statement['opening_date'] = $balance['date'];
                    $statement['currency'] = $statement['currency'] ?? $balance['currency'];
                    $statement['_opening_intermediate'] = $token['tag'] === '60M';
                    $seenBody = true;
                    break;

                case '61':
                    $transaction = $this->transaction($value, $warnings);
                    $seenBody = true;

                    if ($transaction !== null) {
                        $statement['transactions'][] = $transaction;
                        $last = count($statement['transactions']) - 1;
                    }
                    else {
                        $last = null;
                    }

                    break;

                case '86':
                    if ($last !== null) {
                        $transaction = &$statement['transactions'][$last];
                        $info = $this->info($value, $transaction['direction'], $statement['account_iban']);

                        $transaction['counterparty_name'] = $info['name'];
                        $transaction['counterparty_iban'] = $info['iban'];
                        $transaction['description'] = $info['description'] ?? $transaction['description'];
                        $transaction['reference'] = $transaction['reference'] ?? $info['reference'];
                        unset($transaction);

                        $last = null;
                    }

                    break;

                case '62F':
                case '62M':
                    $last = null; // AFV (S10): a :86: after the closing balance describes the statement, not the last :61:
                    $balance = $this->balance($firstLine);

                    if ($balance === null) {
                        $warnings[] = __('databackd-gaps-bank-import.warnings.bad_balance', ['tag' => ':' . $token['tag'] . ':', 'value' => mb_substr($firstLine, 0, 40)]);
                        break;
                    }

                    $statement['closing_balance'] = $balance['amount'];
                    $statement['closing_date'] = $balance['date'];
                    $statement['currency'] = $statement['currency'] ?? $balance['currency'];
                    $statement['_closing_intermediate'] = $token['tag'] === '62M';
                    $seenBody = true;
                    break;
            }
        }

        if (!$seenBody) {
            return null;
        }

        $statement['currency'] = $statement['currency'] ?? $accountCurrency;

        if ($statement['currency'] === null) {
            $statement['currency'] = 'EUR';
            $warnings[] = __('databackd-gaps-bank-import.warnings.no_currency');
        }

        foreach ($statement['transactions'] as $i => $transaction) {
            $statement['transactions'][$i]['currency'] = $statement['currency'];
        }

        return $statement;
    }

    /** Header dates (from/to) and clean-up of the private flags. */
    protected function finish(array $statement): array
    {
        $dates = array_filter(array_merge(
            [$statement['opening_date']],
            array_column($statement['transactions'], 'date')
        ));
        $endDates = array_filter(array_merge(
            [$statement['closing_date']],
            array_column($statement['transactions'], 'date')
        ));

        $statement['date_from'] = $dates ? min($dates) : null;
        $statement['date_to'] = $endDates ? max($endDates) : null;

        unset($statement['_opening_intermediate'], $statement['_closing_intermediate']);

        return $statement;
    }

    // ------------------------------------------------------------------ field parsers

    /**
     * ":60F:C261001EUR1000,00" => amount signed, date, currency
     *
     * @return array{amount: float, date: string|null, currency: string}|null
     */
    protected function balance(string $value): ?array
    {
        if (!preg_match('/^([CD])(\d{6})([A-Z]{3})([\d.,]+)$/', str_replace(' ', '', $value), $m)) {
            return null;
        }

        $amount = BankStatementParser::number($m[4]);

        if ($amount === null) {
            return null;
        }

        return [
            'amount' => $m[1] === 'D' ? -$amount : $amount,
            'date' => $this->date6($m[2]),
            'currency' => $m[3],
        ];
    }

    /**
     * ":61:2610121012D123,45NTRFNONREF//BANKREF" (+ supplementary details on the next line).
     */
    protected function transaction(string $value, array &$warnings): ?array
    {
        $lines = explode("\n", $value);
        $main = trim($lines[0]);
        $supplementary = trim(implode(' ', array_map('trim', array_slice($lines, 1))));

        if (!preg_match('/^(\d{6})(\d{4})?(R?[CD])([A-Z])?(\d[\d.,]*)(?:([NFS][A-Z0-9]{3}))?(.*)$/', $main, $m)) {
            $warnings[] = __('databackd-gaps-bank-import.warnings.bad_transaction', ['value' => mb_substr($main, 0, 60)]);

            return null;
        }

        $amount = BankStatementParser::number($m[5]);
        $valueDate = $this->date6($m[1]);

        if ($amount === null || $amount == 0.0 || $valueDate === null) {
            $warnings[] = __('databackd-gaps-bank-import.warnings.bad_transaction', ['value' => mb_substr($main, 0, 60)]);

            return null;
        }

        // C = credit, D = debit, RC = reversal of a credit (money out), RD = reversal of a debit (money in)
        $credit = in_array($m[3], ['C', 'RD'], true);
        $bookingDate = $m[2] !== '' ? $this->bookingDate($valueDate, $m[2]) : $valueDate;

        $parts = explode('//', trim($m[7]), 2);
        $reference = trim($parts[0]);
        $bankReference = isset($parts[1]) ? trim($parts[1]) : null;

        if ($reference === '' || strtoupper($reference) === 'NONREF') {
            $reference = null;
        }

        return [
            'date' => $bookingDate,
            'value_date' => $valueDate,
            'amount' => $credit ? $amount : -$amount,
            'direction' => $credit ? 'credit' : 'debit',
            'currency' => null,
            'counterparty_name' => null,
            'counterparty_iban' => null,
            'description' => $supplementary !== '' ? $supplementary : null,
            'reference' => $reference !== null ? mb_substr($reference, 0, 60) : null,
            'bank_reference' => $bankReference !== null && $bankReference !== '' ? mb_substr($bankReference, 0, 60) : null,
            'type_code' => $m[6] !== '' ? $m[6] : null,
        ];
    }

    protected function date6(string $yymmdd): ?string
    {
        $year = 2000 + (int)substr($yymmdd, 0, 2);
        $month = (int)substr($yymmdd, 2, 2);
        $day = (int)substr($yymmdd, 4, 2);

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /** MMDD booking date: the year that puts it closest to the value date (a value date of 30 Dec may be booked on 02 Jan). */
    protected function bookingDate(string $valueDate, string $mmdd): string
    {
        $month = (int)substr($mmdd, 0, 2);
        $day = (int)substr($mmdd, 2, 2);
        $valueYear = (int)substr($valueDate, 0, 4);
        $valueTime = strtotime($valueDate);
        $best = null;

        foreach ([$valueYear, $valueYear - 1, $valueYear + 1] as $year) {
            if (!checkdate($month, $day, $year)) {
                continue;
            }

            $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $distance = abs(strtotime($candidate) - $valueTime);

            if ($best === null || $distance < $best[1]) {
                $best = [$candidate, $distance];
            }
        }

        return $best[0] ?? $valueDate;
    }

    // ------------------------------------------------------------------ :86:

    /**
     * @return array{name: string|null, iban: string|null, description: string|null, reference: string|null}
     */
    protected function info(string $raw, string $direction, ?string $ownIban): array
    {
        $raw = $this->joinLines($raw);

        if ($raw === '') {
            return ['name' => null, 'iban' => null, 'description' => null, 'reference' => null];
        }

        if (preg_match_all('/\?\d{2}/', $raw) >= 2) {
            $result = $this->infoSubfields($raw);
        }
        elseif (preg_match('~(^|\s)/(' . self::SLASH_KEYS . ')/~', $raw)) {
            $result = $this->infoSlash($raw);
        }
        else {
            $result = $this->infoFreeText($raw, $direction);
        }

        $iban = Iban::normalize($result['iban'] ?? null);

        if ($iban === null) {
            $iban = Iban::find($raw, $ownIban);
        }

        if ($iban !== null && $ownIban !== null && $iban === Iban::normalize($ownIban)) {
            $iban = null;
        }

        // SEPA remittance codes (German banks and others): EREF+ end-to-end reference, SVWZ+ purpose, KREF+ bank reference
        $description = $result['description'] ?? null;
        $reference = $result['reference'] ?? null;

        if ($description !== null && preg_match('/\b(?:EREF|SVWZ|KREF|MREF)\+/', $description)) {
            if ($reference === null && preg_match('/\bEREF\+\s*(\S+)/u', $description, $m) && strtoupper($m[1]) !== 'NOTPROVIDED') {
                $reference = $m[1];
            }

            if (preg_match('/\bSVWZ\+\s*(.+?)(?=\s*\b(?:EREF|KREF|MREF|CRED|DEBT|ABWA|ABWE)\+|$)/u', $description, $m)) {
                $description = $m[1];
            }
        }

        return [
            'name' => $this->clean($result['name'] ?? null),
            'iban' => $iban,
            'description' => $this->clean($description),
            'reference' => $this->clean($reference, 60),
        ];
    }

    /** German / structured style: "166?00TEXT?20REMITTANCE?21...?30BANK?31IBAN?32NAME?33NAME". */
    protected function infoSubfields(string $raw): array
    {
        $joined = $raw; // the lines are already joined by info()
        $parts = preg_split('/\?(\d{2})/', $joined, -1, PREG_SPLIT_DELIM_CAPTURE);
        $fields = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $fields[$parts[$i]][] = $parts[$i + 1] ?? '';
        }

        $join = function (array $codes) use ($fields) {
            $text = '';
            $previousLength = 0;

            foreach ($codes as $code) {
                foreach ($fields[$code] ?? [] as $piece) {
                    // a full-width subfield (27 chars) usually continues in the next one
                    $glue = $text !== '' && $previousLength < 27 && !str_ends_with($text, ' ') && !str_starts_with($piece, ' ') ? ' ' : '';
                    $text .= $glue . $piece;
                    $previousLength = mb_strlen($piece);
                }
            }

            return trim($text);
        };

        $purpose = $join(array_merge(range(20, 29), range(60, 63)));
        $name = $join([32, 33]);
        $iban = isset($fields['31']) ? Iban::find(implode('', $fields['31'])) ?? Iban::normalize(implode('', $fields['31'])) : null;

        $reference = null;
        $description = $purpose;

        if ($description === '' || $description === null) {
            $description = $join([0]);
        }

        return ['name' => $name !== '' ? $name : null, 'iban' => $iban, 'description' => $description, 'reference' => $reference];
    }

    /** "/EREF/INV1/BENM//NAME/ACME LTD/REMI/Invoice 5" style. */
    protected function infoSlash(string $raw): array
    {
        $joined = $raw;

        if (!preg_match_all('~/(' . self::SLASH_KEYS . ')/~', $joined, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $pairs = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($joined);
            $pairs[] = [$matches[1][$i][0], trim(substr($joined, $start, $end - $start))];
        }

        $name = null;
        $iban = null;
        $reference = null;
        $remittance = [];
        $container = null;

        foreach ($pairs as [$key, $value]) {
            switch ($key) {
                case 'ORDP':
                case 'BENM':
                case 'ULTD':
                case 'ULTB':
                case 'DEBT':
                case 'CRED':
                    $container = $key;

                    if ($value !== '' && $name === null) {
                        $name = $value;
                    }

                    break;

                case 'ACCW':
                case 'ABWA':
                    $container = 'ACCW'; // the bank of the counterparty: its name is not the counterparty
                    break;

                case 'CNTP':
                    $container = 'CNTP';
                    $segments = array_map('trim', explode('/', $value));
                    $iban = $iban ?? ($segments[0] !== '' ? $segments[0] : null);

                    if (($segments[2] ?? '') !== '' && $name === null) {
                        $name = $segments[2];
                    }

                    break;

                case 'NAME':
                    if ($container !== 'ACCW' && $name === null && $value !== '') {
                        $name = $value;
                    }

                    break;

                case 'IBAN':
                    if ($container !== 'ACCW' && $iban === null && $value !== '') {
                        $iban = $value;
                    }

                    break;

                case 'ID':
                    if ($container !== 'ACCW' && $iban === null && Iban::isValid($value)) {
                        $iban = $value;
                    }

                    break;

                case 'EREF':
                case 'KREF':
                case 'MREF':
                    if ($reference === null && $value !== '' && strtoupper($value) !== 'NOTPROVIDED') {
                        $reference = $value;
                    }

                    break;

                case 'REMI':
                case 'SVWZ':
                    if ($value !== '') {
                        $remittance[] = $value;
                    }

                    break;
            }
        }

        $description = $remittance ? implode(' ', $remittance) : preg_replace('~/+~', ' ', $joined);

        return ['name' => $name, 'iban' => $iban, 'description' => $description, 'reference' => $reference];
    }

    /** Free text of the Bulgarian banks: the whole text is the description; the counterparty is taken from labels. */
    protected function infoFreeText(string $raw, string $direction): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $raw));
        $labels = $direction === 'credit' ? self::LABELS_CREDIT : self::LABELS_DEBIT;
        $other = $direction === 'credit' ? self::LABELS_DEBIT : self::LABELS_CREDIT;
        $name = null;

        foreach (array_merge($labels, array_diff($other, $labels)) as $label) {
            $pattern = '/(?:^|[\s;|,(])' . preg_quote($label, '/') . '\s*[:=\-]\s*(.+?)(?=\s*(?:[;|]|\bIBAN\b|\bBIC\b|\bЕИК\b|\bОсновани|\bReason\b|\bDetails\b|\bRef(?:erence)?\b|\bСума\b|\bAmount\b|$))/iu';

            if (preg_match($pattern, $text, $m)) {
                $candidate = trim($m[1], " \t.,;:-");

                if ($candidate !== '' && mb_strlen($candidate) >= 2 && !preg_match('/^[\d\s.,\-]+$/u', $candidate)) {
                    $name = $candidate;
                    break;
                }
            }
        }

        $reference = null;

        if (preg_match('/(?:Ref(?:erence)?|Реф(?:еренция)?\.?|Документ|Doc(?:ument)?(?:\s*No)?)\s*[:#№]\s*([A-Za-z0-9\/\-_.]{3,40})/iu', $text, $m)) {
            $reference = $m[1];
        }

        return ['name' => $name, 'iban' => null, 'description' => $text, 'reference' => $reference];
    }

    /**
     * The lines of a :86: field as one text: a full-width line (the bank wraps at 65 characters, in the middle of a word) is
     * continued directly by the next one, a shorter line is followed by a space.
     */
    protected function joinLines(string $raw): string
    {
        $lines = explode("\n", $raw);
        $text = '';

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $text = $line;
                continue;
            }

            $text .= (mb_strlen($lines[$i - 1]) >= 60 ? '' : ' ') . $line;
        }

        return trim($text);
    }

    protected function clean(?string $value, int $limit = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

}
