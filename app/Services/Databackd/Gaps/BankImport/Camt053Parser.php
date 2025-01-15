<?php

namespace App\Services\Databackd\Gaps\BankImport;

/**
 * ISO 20022 camt.053 (BkToCstmrStmt) bank-to-customer statement parser, versions 001.02 ... 001.08+ (the namespace is ignored).
 *
 * Security: DOCTYPE / entity declarations are rejected, libxml runs with LIBXML_NONET and without LIBXML_NOENT / DTDLOAD
 * (no XXE, no network, no entity expansion).
 *
 * Returns ['statements' => [...], 'warnings' => [...]] - the statement structure is documented in BankStatementParser.
 */
class Camt053Parser
{

    public function parse(string $content): array
    {
        $dom = $this->load($content);
        $warnings = [];
        $statements = [];

        $nodes = $dom->getElementsByTagNameNS('*', 'Stmt');

        if ($nodes->length === 0) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.no_data'));
        }

        foreach ($nodes as $node) {
            $statements[] = $this->statement($node, $warnings);
        }

        return ['statements' => $statements, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ XML

    protected function load(string $content): \DOMDocument
    {
        $content = BankStatementParser::stripBom($content);

        // CAMT files have no DTD: refuse anything that declares one (XXE, "billion laughs").
        // AFV (S10): checked on the UTF-8 text (a UTF-16 file hides the markup between NUL bytes in the raw bytes) and on
        // the raw bytes without NULs (UTF-16 / UTF-32 without a BOM); the loaded document is checked again below.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', BankStatementParser::toUtf8($content))
            || preg_match('/<!(DOCTYPE|ENTITY)/i', str_replace("\0", '', $content))) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.xml_unsafe'));
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = $this->loadXml($content);

            if ($dom === null) {
                // wrong or missing encoding declaration: decode ourselves and drop the declaration
                $text = BankStatementParser::toUtf8($content);
                $text = preg_replace('/(<\?xml[^>]*?)\s+encoding\s*=\s*(["\'])[^"\']*\2/i', '$1', $text, 1);
                $dom = $this->loadXml($text);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($dom === null) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.bad_xml'));
        }

        // AFV (S10): whatever the encoding, a document with a DTD is refused (entity declarations live only in a DTD)
        if ($dom->doctype !== null) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.xml_unsafe'));
        }

        return $dom;
    }

    protected function loadXml(string $xml): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        return @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) ? $dom : null;
    }

    /** @return \DOMElement[] child elements with this local name */
    protected function kids(?\DOMNode $node, string $name): array
    {
        $result = [];

        if ($node === null) {
            return $result;
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                $result[] = $child;
            }
        }

        return $result;
    }

    /** All elements at a "A/B/C" path below the node (every repeated element is followed). @return \DOMElement[] */
    protected function all(?\DOMNode $node, string $path): array
    {
        $nodes = $node === null ? [] : [$node];

        foreach (explode('/', $path) as $name) {
            $next = [];

            foreach ($nodes as $current) {
                foreach ($this->kids($current, $name) as $child) {
                    $next[] = $child;
                }
            }

            $nodes = $next;
        }

        return $nodes;
    }

    protected function at(?\DOMNode $node, string $path): ?\DOMElement
    {
        return $this->all($node, $path)[0] ?? null;
    }

    protected function val(?\DOMNode $node, string $path): ?string
    {
        $element = $this->at($node, $path);

        if ($element === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $element->textContent));

        return $text === '' ? null : $text;
    }

    /** First non-empty value of several paths. */
    protected function first(?\DOMNode $node, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->val($node, $path);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function dateOf(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    // ------------------------------------------------------------------ statement

    protected function statement(\DOMElement $stmt, array &$warnings): array
    {
        $iban = $this->first($stmt, ['Acct/Id/IBAN', 'Acct/Id/Othr/Id']);
        $currency = $this->val($stmt, 'Acct/Ccy');
        $id = $this->val($stmt, 'Id');
        $number = $this->first($stmt, ['LglSeqNb', 'ElctrncSeqNb']) ?? $id;

        // balances: opening OPBD (or PRCD), closing CLBD; AFV (S10): a page of a paginated statement may carry only interim
        // booked balances (ITBD, end-of-day snapshots) - see below
        $opening = $openingDate = $closing = $closingDate = null;
        $openingRank = $closingRank = 99;
        $interim = [];

        foreach ($this->all($stmt, 'Bal') as $bal) {
            $type = $this->first($bal, ['Tp/CdOrPrtry/Cd', 'Tp/CdOrPrtry/Prtry']);
            $amountEl = $this->at($bal, 'Amt');
            $amount = $amountEl ? BankStatementParser::number($amountEl->textContent) : null;

            if ($amount === null) {
                continue;
            }

            $amount = $this->val($bal, 'CdtDbtInd') === 'DBIT' ? -$amount : $amount;
            $date = $this->dateOf($this->first($bal, ['Dt/Dt', 'Dt/DtTm']));
            $ccy = $amountEl->getAttribute('Ccy') ?: null;
            $currency = $currency ?? $ccy;

            if (in_array($type, ['OPBD', 'PRCD'], true)) {
                $rank = $type === 'OPBD' ? 0 : 1;

                if ($rank < $openingRank) {
                    [$opening, $openingDate, $openingRank] = [$amount, $date, $rank];
                }
            }
            elseif ($type === 'CLBD' && $closingRank > 0) {
                [$closing, $closingDate, $closingRank] = [$amount, $date, 0];
            }
            elseif ($type === 'ITBD' && $date !== null) {
                $interim[] = [$amount, $date];
            }
        }

        if ($currency === null) {
            $currency = 'EUR';
            $warnings[] = __('databackd-gaps-bank-import.warnings.no_currency');
        }

        $transactions = [];
        $skipped = 0;

        foreach ($this->all($stmt, 'Ntry') as $ntry) {
            $status = $this->first($ntry, ['Sts/Cd', 'Sts']);

            if ($status !== null && in_array(strtoupper($status), ['PDNG', 'INFO', 'FUTR'], true)) {
                $skipped++;
                continue;
            }

            foreach ($this->entry($ntry, $currency, Iban::normalize($iban), $warnings) as $transaction) {
                $transactions[] = $transaction;
            }
        }

        if ($skipped > 0) {
            $warnings[] = __('databackd-gaps-bank-import.warnings.not_booked', ['count' => $skipped]);
        }

        // only interim balances: the latest one closes; the earliest one opens only when it is dated before every entry (an
        // ITBD is the balance at the end of its day, so one dated on the day of the entries already contains them)
        if ($interim) {
            usort($interim, fn($a, $b) => strcmp($a[1], $b[1]));
            $firstEntry = ($dates = array_filter(array_column($transactions, 'date'))) ? min($dates) : null;

            if ($closingRank === 99) {
                [$closing, $closingDate] = end($interim);
            }

            if ($openingRank === 99 && count($interim) >= 2 && $firstEntry !== null && $interim[0][1] < $firstEntry) {
                [$opening, $openingDate] = $interim[0];
            }
        }

        // period: FrToDt or the dates of the balances and transactions
        $from = $this->dateOf($this->val($stmt, 'FrToDt/FrDtTm'));
        $to = $this->dateOf($this->val($stmt, 'FrToDt/ToDtTm'));
        $txDates = array_filter(array_column($transactions, 'date'));
        $startDates = array_filter(array_merge($from ? [$from] : [], $openingDate ? [$openingDate] : [], $txDates));
        $endDates = array_filter(array_merge($to ? [$to] : [], $closingDate ? [$closingDate] : [], $txDates));

        return [
            'format' => 'camt053',
            'reference' => $id !== null ? mb_substr($id, 0, 60) : null,
            'statement_no' => $number !== null ? mb_substr($number, 0, 60) : null,
            'account_iban' => Iban::normalize($iban),
            'currency' => strtoupper($currency),
            'date_from' => $startDates ? min($startDates) : null,
            'date_to' => $endDates ? max($endDates) : null,
            'opening_balance' => $opening,
            'opening_date' => $openingDate,
            'closing_balance' => $closing,
            'closing_date' => $closingDate,
            'transactions' => $transactions,
        ];
    }

    /**
     * One Ntry => one transaction, or one per TxDtls when the entry is a batch whose parts add up to the entry amount.
     *
     * @return array<int, array>
     */
    protected function entry(\DOMElement $ntry, string $statementCurrency, ?string $ownIban, array &$warnings): array
    {
        $amountEl = $this->at($ntry, 'Amt');
        $amount = $amountEl ? BankStatementParser::number($amountEl->textContent) : null;
        $indicator = strtoupper((string)$this->val($ntry, 'CdtDbtInd'));

        if ($amount === null || $amount == 0.0 || !in_array($indicator, ['CRDT', 'DBIT'], true)) {
            $warnings[] = __('databackd-gaps-bank-import.warnings.bad_transaction', ['value' => mb_substr((string)$this->val($ntry, 'NtryRef'), 0, 60)]);

            return [];
        }

        $credit = $indicator === 'CRDT';
        $currency = strtoupper($amountEl->getAttribute('Ccy') ?: $statementCurrency);
        $bookingDate = $this->dateOf($this->first($ntry, ['BookgDt/Dt', 'BookgDt/DtTm']));
        $valueDate = $this->dateOf($this->first($ntry, ['ValDt/Dt', 'ValDt/DtTm']));
        $date = $bookingDate ?? $valueDate;

        if ($date === null) {
            $warnings[] = __('databackd-gaps-bank-import.warnings.bad_transaction', ['value' => mb_substr((string)$this->val($ntry, 'NtryRef'), 0, 60)]);

            return [];
        }

        $entryRef = $this->first($ntry, ['AcctSvcrRef', 'NtryRef']);
        $entryInfo = $this->val($ntry, 'AddtlNtryInf');
        $typeCode = $this->typeCode($ntry);
        $details = $this->all($ntry, 'NtryDtls/TxDtls');

        $base = [
            'date' => $date,
            'value_date' => $valueDate,
            'direction' => $credit ? 'credit' : 'debit',
            'currency' => $currency,
            'bank_reference' => $entryRef !== null ? mb_substr($entryRef, 0, 60) : null,
            'type_code' => $typeCode,
        ];

        // batch booking: every part with its own amount (in the currency of the entry) and the parts add up to the entry.
        // AFV (S10): since camt.053.001.03 a part can have its own CdtDbtInd (e.g. a fee inside a batch of receipts),
        // so the parts are added with their sign; TxDtls/Amt (.03+) before AmtDtls/TxAmt/Amt (.02).
        if (count($details) > 1) {
            $parts = [];
            $sum = 0.0;

            foreach ($details as $detail) {
                $partEl = $this->at($detail, 'Amt') ?? $this->at($detail, 'AmtDtls/TxAmt/Amt');
                $part = $partEl ? BankStatementParser::number($partEl->textContent) : null;
                $partCurrency = $partEl ? strtoupper($partEl->getAttribute('Ccy') ?: $currency) : null;
                $partIndicator = strtoupper((string)$this->val($detail, 'CdtDbtInd')) ?: $indicator;

                if ($part === null || $part <= 0 || $partCurrency !== $currency || !in_array($partIndicator, ['CRDT', 'DBIT'], true)) {
                    $parts = [];
                    break;
                }

                $partCredit = $partIndicator === 'CRDT';
                $parts[] = [$detail, $partCredit ? $part : -$part, $partCredit];
                $sum += $partCredit ? $part : -$part;
            }

            if ($parts && abs($sum - ($credit ? $amount : -$amount)) < 0.01) {
                $result = [];

                foreach ($parts as [$detail, $signed, $partCredit]) {
                    $result[] = $this->transaction(array_replace($base, ['direction' => $partCredit ? 'credit' : 'debit']), $detail, $signed, $entryInfo, $partCredit, $ownIban);
                }

                return $result;
            }
        }

        $transaction = $this->transaction($base, $details[0] ?? null, $credit ? $amount : -$amount, $entryInfo, $credit, $ownIban);

        // several parts that could not be split: keep all the remittance texts in one description
        if (count($details) > 1) {
            $texts = [];

            foreach ($details as $detail) {
                $text = $this->remittance($detail);

                if ($text !== null) {
                    $texts[] = $text;
                }
            }

            if ($texts) {
                $transaction['description'] = mb_substr(implode(' | ', $texts), 0, 255);
            }
        }

        return [$transaction];
    }

    protected function transaction(array $base, ?\DOMElement $detail, float $signedAmount, ?string $entryInfo, bool $credit, ?string $ownIban = null): array
    {
        // the counterparty of money in is the debtor, of money out the creditor (Nm directly below the party up to .07, in a Pty
        // wrapper from .08). AFV (S10): when that side is our own account (returns / reversals are reported with the
        // parties of the original payment by some banks) the other side is used if it has an account that is not ours.
        [$name, $iban, $own] = $detail ? $this->party($detail, $credit ? 'Dbtr' : 'Cdtr', $ownIban) : [null, null, false];

        if ($own) {
            [$otherName, $otherIban, $otherOwn] = $this->party($detail, $credit ? 'Cdtr' : 'Dbtr', $ownIban);

            if (!$otherOwn && $otherIban !== null) {
                [$name, $iban] = [$otherName, $otherIban];
            }
            else {
                $iban = null; // never our own account as the counterparty
            }
        }
        $reference = $detail ? $this->first($detail, ['Refs/EndToEndId', 'Refs/TxId', 'Refs/MndtId', 'Refs/InstrId']) : null;

        if ($reference !== null && strtoupper($reference) === 'NOTPROVIDED') {
            $reference = null;
        }

        $reference = $reference ?? ($detail ? $this->first($detail, ['RmtInf/Strd/CdtrRefInf/Ref']) : null);
        $description = ($detail ? $this->remittance($detail) : null) ?? $entryInfo;

        return $base + [
            'amount' => $signedAmount,
            'counterparty_name' => $name !== null ? mb_substr($name, 0, 255) : null,
            'counterparty_iban' => Iban::normalize($iban),
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'reference' => $reference !== null ? mb_substr($reference, 0, 60) : null,
        ];
    }

    /**
     * Name and IBAN of one side of the related parties and whether its account is the own account.
     *
     * @return array{0: string|null, 1: string|null, 2: bool}
     */
    protected function party(\DOMElement $detail, string $side, ?string $ownIban): array
    {
        $name = $this->first($detail, [
            "RltdPties/{$side}/Nm", "RltdPties/{$side}/Pty/Nm", "RltdPties/Ultmt{$side}/Nm", "RltdPties/Ultmt{$side}/Pty/Nm",
        ]);
        $iban = Iban::normalize($this->first($detail, ["RltdPties/{$side}Acct/Id/IBAN", "RltdPties/{$side}Acct/Id/Othr/Id"]));

        return [$name, $iban, $iban !== null && $iban === $ownIban];
    }

    /** Unstructured remittance information (all lines), else structured reference, else additional transaction info. */
    protected function remittance(?\DOMElement $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        $lines = [];

        foreach ($this->all($detail, 'RmtInf/Ustrd') as $line) {
            $text = trim(preg_replace('/\s+/u', ' ', $line->textContent));

            if ($text !== '') {
                $lines[] = $text;
            }
        }

        if ($lines) {
            return implode(' ', $lines);
        }

        return $this->first($detail, ['RmtInf/Strd/AddtlRmtInf', 'RmtInf/Strd/CdtrRefInf/Ref', 'AddtlTxInf']);
    }

    protected function typeCode(\DOMElement $ntry): ?string
    {
        $domain = $this->val($ntry, 'BkTxCd/Domn/Cd');
        $family = $this->val($ntry, 'BkTxCd/Domn/Fmly/Cd');
        $sub = $this->val($ntry, 'BkTxCd/Domn/Fmly/SubFmlyCd');
        $code = implode('-', array_filter([$domain, $family, $sub]));

        return $code !== '' ? $code : $this->val($ntry, 'BkTxCd/Prtry/Cd');
    }

}
